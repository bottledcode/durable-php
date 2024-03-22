package auth

import (
	"context"
	"durable_php/appcontext"
	"durable_php/glue"
	"encoding/json"
	"fmt"
	"github.com/nats-io/nats.go/jetstream"
	"go.uber.org/zap"
	"io"
	"net/http"
	"os"
	"slices"
	"sync"
	"time"
)

type Resource struct {
	Owners   map[UserId]struct{} `json:"owner"`
	Shares   []Share             `json:"Shares"`
	Mode     Mode                `json:"mode"`
	mu       sync.RWMutex
	kv       jetstream.KeyValue
	id       *glue.StateId
	Expires  time.Time
	revision uint64
}

func NewResourcePermissions(owner *User, mode Mode) *Resource {
	if owner == nil {
		return &Resource{
			Owners:  map[UserId]struct{}{},
			Shares:  make([]Share, 0),
			Mode:    mode,
			mu:      sync.RWMutex{},
			Expires: time.Now(),
		}
	}

	return &Resource{
		Owners:  map[UserId]struct{}{owner.UserId: {}},
		Shares:  []Share{},
		Mode:    mode,
		mu:      sync.RWMutex{},
		Expires: time.Now(),
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

func (r *Resource) Update(ctx context.Context, logger *zap.Logger) error {
	_, err := r.kv.Update(ctx, r.id.ToSubject().String(), r.toBytes(), r.revision)
	if err != nil {
		return err
	}

	return nil
}

// ShareOwnership to another user, optionally keeping all permissions if the user still wants to access the resource
func (r *Resource) ShareOwnership(newUser UserId, keepPermissions bool, ctx context.Context) error {
	if r.WantTo(Owner, ctx) {
		r.mu.Lock()
		defer r.mu.Unlock()

		if cu := ctx.Value(appcontext.CurrentUserKey).(*User); cu != nil && !keepPermissions {
			delete(r.Owners, cu.UserId)
		}

		r.Owners[newUser] = struct{}{}
	}

	return fmtError(Owner)
}

func (r *Resource) ApplyPerms(id *glue.StateId, ctx context.Context, logger *zap.Logger) bool {
	var perms CreatePermissions

	if cached, found := cache.Load(id.Name()); found {
		perms = cached.(CreatePermissions)
	} else {
		result, err := os.CreateTemp("", "")
		if err != nil {
			panic(err)
		}
		env := map[string]string{"STATE_ID": id.String()}
		glu := glue.NewGlue("", glue.GetPermissions, make([]any, 0), result.Name())
		glu.Execute(ctx, make(http.Header), logger, env, nil, id)

		data, err := io.ReadAll(result)
		if err != nil {
			panic(err)
		}
		err = json.Unmarshal(data, &perms)
		if err != nil {
			return false
		}
		cache.Store(id.Name(), perms)
	}

	if r.Mode != perms.Mode {
		r.Mode = perms.Mode
		return true
	}

	return false
}

func (r *Resource) CanCreate(id *glue.StateId, ctx context.Context, logger *zap.Logger) bool {
	var perms CreatePermissions
	if cached, found := cache.Load(id.Name()); found {
		perms = cached.(CreatePermissions)
	} else {
		result, err := os.CreateTemp("", "")
		if err != nil {
			panic(err)
		}
		defer os.Remove(result.Name())
		result.Close()

		glu := glue.NewGlue(ctx.Value("bootstrap").(string), glue.GetPermissions, make([]any, 0), result.Name())
		env := map[string]string{"STATE_ID": id.String()}
		_, headers, _ := glu.Execute(ctx, make(http.Header), logger, env, nil, id)

		data := headers.Get("Permissions")

		err = json.Unmarshal([]byte(data), &perms)
		if err != nil {
			return false
		}

		cache.Store(id.Name(), perms)
	}

	currentUser, _ := ctx.Value(appcontext.CurrentUserKey).(*User)
	r.Mode = perms.Mode
	r.Expires = time.Now().Add(time.Duration(perms.TimeToLive) * time.Nanosecond)

	switch perms.Mode {
	case AnonymousMode:
		return true
	case AuthenticatedMode:
		if currentUser == nil {
			return false
		}
		return true
	case ExplicitMode:
		if currentUser == nil {
			return false
		}
		if slices.Contains(perms.Users, currentUser.UserId) {
			return true
		}

		for _, role := range currentUser.Roles {
			if slices.Contains(perms.Roles, role) {
				return true
			}
		}

		return false
	}

	return false
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
	if user := ctx.Value(appcontext.CurrentUserKey).(*User); user != nil {
		if _, found := r.Owners[user.UserId]; found {
			return true
		}
	}
	return false
}

func (r *Resource) WantTo(operation Operation, ctx context.Context) (able bool) {
	user, _ := ctx.Value(appcontext.CurrentUserKey).(*User)

	if r.Mode == AnonymousMode {
		return true
	}

	// only owners can change
	if r.Mode == AuthenticatedMode && user != nil {
		if !r.IsOwner(ctx) && operation == Owner {
			return false
		}
		return true
	}

	if user == nil {
		return false
	}

	if user.IsAdmin() {
		return true
	}

	// delay the lock as long as possible
	r.mu.RLock()
	defer r.mu.RUnlock()

	if user != nil {
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

func (r *Resource) GrantUser(user UserId, operation Operation, ctx context.Context) error {
	if !r.WantTo(SharePlus, ctx) {
		return fmtError(SharePlus)
	}
	r.mu.Lock()
	defer r.mu.Unlock()

	for _, x := range r.Shares {
		if u, ok := x.(UserShare); ok && u.UserId == user {
			u.AllowedOperations[operation] = struct{}{}
			return nil
		}
	}

	r.Shares = append(r.Shares, UserShare{
		UserId: user,
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
