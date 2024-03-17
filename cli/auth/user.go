package auth

import (
	"context"
	"encoding/json"
	"fmt"
	"slices"
	"sync"
)

type KvType string
type Mode string
type Operation string

type UserId string
type Role string

type ContextKey struct{}

var CurrentUserKey ContextKey

const (
	// ExplicitMode configures the service to only allow explicit Shares
	ExplicitMode Mode = "explicit"

	// AuthenticatedMode configures the service for all authenticated users
	AuthenticatedMode Mode = "auth"

	// AnonymousMode configures the service for any and all users
	AnonymousMode Mode = "anon"

	// Signal whether the share can send signals to the orchestration/entity
	Signal Operation = "signal"

	// Completion whether the share can poll for orchestration completion
	Completion Operation = "completion"

	// Output whether the share can retrieve the orchestration output
	Output Operation = "output"

	// Call whether the share can call an entity
	Call Operation = "call"

	// Lock whether the share can lock an entity
	Lock Operation = "lock"

	// SharePlus whether the share can invite more shares
	SharePlus Operation = "share+"

	// ShareMinus whether the share can see other shares and manage them
	ShareMinus Operation = "share-"

	// Owner operations allow transferring ownership
	Owner Operation = "owner"
)

type User struct {
	UserId UserId `json:"userId"`
	Roles  []Role `json:"roles"`
}

func (u *User) IsAdmin() bool {
	return u.Is("admin")
}

func (u *User) Is(role Role) bool {
	return slices.Contains(u.Roles, role)
}

type Share interface {
	// WantTo returns true if the user can perform the Operation
	WantTo(operation Operation, ctx context.Context) bool
}

type Permissions struct {
	AllowedOperations map[Operation]struct{} `json:"allowedOperations"`
}

func (p Permissions) WantTo(operation Operation, ctx context.Context) bool {
	if _, exists := p.AllowedOperations[operation]; exists {
		return true
	}

	return false
}

type UserShare struct {
	UserId UserId `json:"userId"`
	Permissions
}

func (u UserShare) WantTo(operation Operation, ctx context.Context) bool {
	if user, ok := ctx.Value(CurrentUserKey).(User); ok && user.UserId == u.UserId {
		return u.Permissions.WantTo(operation, ctx)
	}

	return false
}

type RoleShare struct {
	Role Role `json:"role"`
	Permissions
}

func (r RoleShare) WantTo(operation Operation, ctx context.Context) bool {
	if user, ok := ctx.Value(CurrentUserKey).(User); ok && user.Is(r.Role) {
		return r.Permissions.WantTo(operation, ctx)
	}

	return false
}

type Resource struct {
	Owners map[UserId]struct{} `json:"owner"`
	Shares []Share             `json:"Shares"`
	Mode   Mode                `json:"mode"`
	mu     sync.RWMutex
}

func NewResourcePermissions(owner User, mode Mode) Resource {
	return Resource{
		Owners: map[UserId]struct{}{owner.UserId: {}},
		Shares: []Share{},
		Mode:   mode,
		mu:     sync.RWMutex{},
	}
}

func FromBytes(data []byte) *Resource {
	var resource Resource
	err := json.Unmarshal(data, &resource)
	if err != nil {
		panic(err)
	}
	resource.mu = sync.RWMutex{}

	return &resource
}

// ShareOwnership to another user, optionally keeping all permissions if the user still wants to access the resource
func (r *Resource) ShareOwnership(newUser User, keepPermissions bool, ctx context.Context) error {
	if cu, ok := ctx.Value(CurrentUserKey).(User); ok {
		if _, found := r.Owners[cu.UserId]; !found {
			return fmtError(Owner)
		}

		r.mu.Lock()
		defer r.mu.Unlock()

		if !keepPermissions {
			delete(r.Owners, cu.UserId)
		}

		r.Owners[newUser.UserId] = struct{}{}
	}

	return fmtError(Owner)
}

func (r *Resource) toBytes() []byte {
	r.mu.RLock()
	defer r.mu.RUnlock()
	data, err := json.Marshal(r)
	if err != nil {
		panic(err)
	}
	return data
}

func (r *Resource) WantTo(operation Operation, ctx context.Context) (ok bool) {
	user, ok := ctx.Value(CurrentUserKey).(User)

	if r.Mode == AnonymousMode {
		return true
	}

	if r.Mode == AuthenticatedMode && ok {
		return true
	}

	// delay the lock as long as possible
	r.mu.RLock()
	defer r.mu.RUnlock()

	if ok {
		if _, found := r.Owners[user.UserId]; found {
			return true
		}
	}

	for _, share := range r.Shares {
		if share.WantTo(operation, ctx) {
			return true
		}
	}

	return false
}

func fmtError(operation Operation) error {
	return fmt.Errorf("operation %s not allowed by current context", operation)
}

func (r *Resource) Grant(share Share, ctx context.Context) error {
	if r.WantTo(SharePlus, ctx) {
		r.mu.Lock()
		defer r.mu.Unlock()
		r.Shares = append(r.Shares, share)
		return nil
	}
	return fmtError(SharePlus)
}

func (r *Resource) GrantUser(user User, operation Operation, ctx context.Context) error {
	if !r.WantTo(SharePlus, ctx) {
		return fmtError(SharePlus)
	}
	r.mu.Lock()
	defer r.mu.Unlock()

	for _, x := range r.Shares {
		if u, ok := x.(UserShare); ok && u.UserId == user.UserId {
			u.AllowedOperations[operation] = struct{}{}
			return nil
		}
	}

	r.Shares = append(r.Shares, UserShare{
		UserId: user.UserId,
		Permissions: Permissions{
			AllowedOperations: map[Operation]struct{}{
				operation: {},
			},
		},
	})

	return nil
}

func (r *Resource) GrantRole(role Role, operation Operation, ctx context.Context) error {
	if !r.WantTo(SharePlus, ctx) {
		return fmtError(SharePlus)
	}
	r.mu.Lock()
	defer r.mu.Unlock()

	for _, x := range r.Shares {
		if r, ok := x.(RoleShare); ok && r.Role == role {
			r.AllowedOperations[operation] = struct{}{}
			return nil
		}
	}

	r.Shares = append(r.Shares, RoleShare{
		Role: role,
		Permissions: Permissions{
			AllowedOperations: map[Operation]struct{}{
				operation: {},
			},
		},
	})
	return nil
}

func (r *Resource) RevokeUser(id UserId, ctx context.Context) error {
	if !r.WantTo(ShareMinus, ctx) {
		return fmtError(ShareMinus)
	}
	r.mu.Lock()
	defer r.mu.Unlock()

	news := make([]Share, 0)
	for _, share := range r.Shares {
		v, ok := share.(UserShare)
		if !ok {
			news = append(news, v)
			continue
		}
		if v.UserId != id {
			news = append(news, v)
			continue
		}

		// not adding element because it matches
	}

	r.Shares = news

	return nil
}

func (r *Resource) RevokeRole(role Role, ctx context.Context) error {
	if !r.WantTo(ShareMinus, ctx) {
		return fmtError(ShareMinus)
	}
	r.mu.Lock()
	defer r.mu.Unlock()

	news := make([]Share, 0)
	for _, share := range r.Shares {
		v, ok := share.(RoleShare)
		if !ok || v.Role != role {
			news = append(news, share)
		}

		// not adding element because it matches
	}

	return nil
}
