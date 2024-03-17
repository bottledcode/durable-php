package auth

import (
	"context"
	"encoding/json"
	"fmt"
	"sync"
)

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
	if r.WantTo(Owner, ctx) {
		r.mu.Lock()
		defer r.mu.Unlock()

		if cu, ok := ctx.Value(CurrentUserKey).(User); ok && !keepPermissions {
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

func (r *Resource) IsOwner(ctx context.Context) bool {
	if user, ok := ctx.Value(CurrentUserKey).(User); ok {
		if _, found := r.Owners[user.UserId]; found {
			return true
		}
	}
	return false
}

func (r *Resource) WantTo(operation Operation, ctx context.Context) (ok bool) {
	user, ok := ctx.Value(CurrentUserKey).(User)

	if r.Mode == AnonymousMode {
		return true
	}

	// only owners can change
	if r.Mode == AuthenticatedMode && ok {
		if !r.IsOwner(ctx) && operation == Owner {
			return false
		}
		return true
	}

	if !ok {
		return false
	}

	if user.IsAdmin() {
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
