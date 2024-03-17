package auth

import (
	"encoding/json"
	"slices"
	"sync"
)

type KvType string
type Mode string
type Operation string

type UserId string
type Role string

const (
	// Owner represents the keyspace for owners
	Owner KvType = "owner"

	// Shares represents the keyspace for Shares
	Shares KvType = "Shares"

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
	WantTo(operation Operation, user *User) bool
}

type Permissions struct {
	AllowedOperations map[Operation]struct{} `json:"allowedOperations"`
}

func (p Permissions) WantTo(operation Operation, user *User) bool {
	if _, exists := p.AllowedOperations[operation]; exists {
		return true
	}

	return false
}

type UserShare struct {
	UserId UserId `json:"userId"`
	Permissions
}

func (u UserShare) WantTo(operation Operation, user *User) bool {
	if u.UserId == user.UserId {
		return u.Permissions.WantTo(operation, user)
	}

	return false
}

type RoleShare struct {
	Role Role `json:"role"`
	Permissions
}

func (r RoleShare) WantTo(operation Operation, user *User) bool {
	if slices.Contains(user.Roles, r.Role) {
		return r.Permissions.WantTo(operation, user)
	}

	return false
}

type Resource struct {
	Owner  UserId  `json:"owner"`
	Shares []Share `json:"Shares"`
	Mode   Mode    `json:"mode"`
	mu     sync.Mutex
}

func NewResourcePermissions(owner User, mode Mode) Resource {
	return Resource{
		Owner:  owner.UserId,
		Shares: []Share{},
		Mode:   mode,
		mu:     sync.Mutex{},
	}
}

func FromBytes(data []byte) *Resource {
	var resource Resource
	err := json.Unmarshal(data, &resource)
	if err != nil {
		panic(err)
	}
	resource.mu = sync.Mutex{}

	return &resource
}

func (r *Resource) toBytes() []byte {
	data, err := json.Marshal(r)
	if err != nil {
		panic(err)
	}
	return data
}

func (r *Resource) WantTo(operation Operation, user *User) bool {
	if user.UserId == r.Owner {
		return true
	}

	if r.Mode == AnonymousMode {
		return true
	}

	if r.Mode == AuthenticatedMode && user != nil {
		return true
	}

	for _, share := range r.Shares {
		if share.WantTo(operation, user) {
			return true
		}
	}

	return false
}

func (r *Resource) Grant(share Share) {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.Shares = append(r.Shares, share)
}

func (r *Resource) GrantUser(user User, operation Operation) {
	r.mu.Lock()
	defer r.mu.Unlock()

	for _, x := range r.Shares {
		if u, ok := x.(UserShare); ok && u.UserId == user.UserId {
			u.AllowedOperations[operation] = struct{}{}
			return
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
}

func (r *Resource) GrantRole(role Role, operation Operation) {
	r.mu.Lock()
	defer r.mu.Unlock()

	for _, x := range r.Shares {
		if r, ok := x.(RoleShare); ok && r.Role == role {
			r.AllowedOperations[operation] = struct{}{}
			return
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
}

func (r *Resource) RevokeUser(id UserId) {
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
}

func (r *Resource) RevokeRole(role Role) {
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
}
