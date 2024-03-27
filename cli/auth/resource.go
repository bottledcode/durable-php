package auth

import (
	"context"
	"durable_php/appcontext"
	"durable_php/glue"
	"encoding/json"
	"errors"
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

// Resource represents a resource with owners, shares, mode, expiration, and revision.
// It allows operations such as updating, sharing ownership, applying permissions,
// checking permissions, and serializing/deserializing to/from bytes.
// Resources are used for controlling access to specific functionalities or data.
//
// The resource has the following fields:
// - Owners: a map of UserId to empty struct{}, representing the owners of the resource.
// - Shares: a slice of Share interfaces, representing the shares of the resource.
// - Mode: a Mode type, representing the access mode of the resource.
// - mu: a sync.RWMutex for synchronization purposes.
// - kv: a jetstream.KeyValue, representing the key-value store for the resource.
// - id: a pointer to glue.StateId, representing the state ID of the resource.
// - Expires: a time.Time, representing the expiration time of the resource.
// - revision: an unsigned integer, representing the revision number of the resource.
//
// Resources can be created using the NewResourcePermissions function, which takes an owner User and a mode as arguments.
//
// Resources can also be created by deserializing from bytes using the FromBytes function.
//
// The Resource type also provides methods for various operations:
// - Update: updates the resource in the key-value store.
// - ShareOwnership: shares the ownership of the resource with a new user.
// - ApplyPerms: applies permissions to the resource based on the provided state ID.
// - CanCreate: checks if the current user can create the resource based on the provided state ID.
// - toBytes: serializes the resource to bytes.
// - IsOwner: checks if the current user is an owner of the resource.
// - WantTo: checks if the current user can perform the given operation on the resource.
// - Grant: grants a share to the resource.
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

// NewResourcePermissions creates a new Resource with the specified owner and mode. If the owner is nil, the resource
// will have no owners. The Share slice will be empty and the Expires
func NewResourcePermissions(owner *User, mode Mode) *Resource {
	res := &Resource{
		Owners:  map[UserId]struct{}{},
		Shares:  []Share{},
		Mode:    mode,
		mu:      sync.RWMutex{},
		Expires: time.Now(),
	}

	if owner != nil && owner.UserId != "" {
		res.Owners[owner.UserId] = struct{}{}
	}

	return res
}

// FromBytes takes a byte slice and returns a pointer to a Resource object. It deserializes the byte slice using JSON
// unmarshaling and initializes the Resource object with the deserialized
func FromBytes(data []byte) (*Resource, error) {
	var resource Resource
	err := json.Unmarshal(data, &resource)
	if err != nil {
		return nil, err
	}
	resource.mu = sync.RWMutex{}

	return &resource, nil
}

// Update updates the Resource in the key-value store with the given context and logger.
func (r *Resource) Update(ctx context.Context, logger *zap.Logger) error {
	_, err := r.kv.Update(ctx, r.id.ToSubject().String(), r.toBytes(), r.revision)
	return err
}

// ShareOwnership to another user, optionally keeping all permissions if the user still wants to access the resource
func (r *Resource) ShareOwnership(newUser UserId, currentUser *User, keepPermissions bool) error {
	if currentUser == nil {
		return errors.New("cannot share with unknown user")
	}

	r.mu.Lock()
	defer r.mu.Unlock()
	if currentUser != nil && !keepPermissions {
		delete(r.Owners, currentUser.UserId)
	}
	r.Owners[newUser] = struct{}{}
	return nil
}

// ApplyPerms applies permissions to the Resource identified by the given StateId. It retrieves the permissions from the
// cache if available, otherwise it retrieves them from the key
func (r *Resource) getPermissions(id *glue.StateId, ctx context.Context, logger *zap.Logger) (CreatePermissions, error) {
	if cached, found := cache.Load(id.Name()); found {
		return cached.(CreatePermissions), nil
	}

	result, err := os.CreateTemp("", "")
	if err != nil {
		return CreatePermissions{}, err
	}

	env := map[string]string{"STATE_ID": id.String()}
	glu := glue.NewGlue("", glue.GetPermissions, make([]any, 0), result.Name())
	glu.Execute(ctx, make(http.Header), logger, env, nil, id)

	data, err := io.ReadAll(result)
	if err != nil {
		return CreatePermissions{}, err
	}

	var perms CreatePermissions
	if err := json.Unmarshal(data, &perms); err != nil {
		return CreatePermissions{}, err
	}

	cache.Store(id.Name(), perms)
	return perms, nil
}

func (r *Resource) ApplyPerms(id *glue.StateId, ctx context.Context, logger *zap.Logger) bool {
	perms, err := r.getPermissions(id, ctx, logger)
	if err != nil {
		logger.Error("failed to get permissions", zap.Error(err))
		return false
	}

	if r.Mode != perms.Mode {
		r.Mode = perms.Mode
		return true
	}
	return false
}

// CanCreate Load permissions from cache if available, otherwise fetch from external source
func (r *Resource) CanCreate(id *glue.StateId, ctx context.Context, logger *zap.Logger) bool {
	perms, err := r.getOrCreatePermissions(id, ctx, logger)
	if err != nil {
		logger.Error("failed to create permissions", zap.Error(err))
		return false
	}
	return r.isUserPermitted(perms, ctx)
}

func (r *Resource) getOrCreatePermissions(id *glue.StateId, ctx context.Context, logger *zap.Logger) (CreatePermissions, error) {
	var perms CreatePermissions
	if cached, found := cache.Load(id.Name()); found {
		perms = cached.(CreatePermissions)
	} else {
		result, err := os.CreateTemp("", "")
		if err != nil {
			return perms, err
		}
		defer os.Remove(result.Name())
		result.Close()

		glu := glue.NewGlue(ctx.Value("bootstrap").(string), glue.GetPermissions, make([]any, 0), result.Name())
		env := map[string]string{"STATE_ID": id.String()}
		_, headers, _ := glu.Execute(ctx, make(http.Header), logger, env, nil, id)
		data := headers.Get("Permissions")
		if err = json.Unmarshal([]byte(data), &perms); err != nil {
			return perms, err
		}
		cache.Store(id.Name(), perms)
	}
	return perms, nil
}

func (r *Resource) isUserPermitted(perms CreatePermissions, ctx context.Context) bool {
	r.Mode = perms.Mode
	r.Expires = time.Now().Add(time.Duration(perms.TimeToLive) * time.Nanosecond)
	switch perms.Mode {
	case AnonymousMode:
		return true
	case AuthenticatedMode:
		maybeUser := ctx.Value(appcontext.CurrentUserKey)
		if maybeUser == nil {
			return false
		}

		if user, ok := maybeUser.(*User); ok && user.UserId != "" {
			return true
		}

		return false
	case ExplicitMode:
		return r.isUserExplicitlyPermitted(perms, ctx)
	}
	return false
}

func (r *Resource) isUserExplicitlyPermitted(perms CreatePermissions, ctx context.Context) bool {
	if user := ctx.Value(appcontext.CurrentUserKey).(*User); user != nil {
		if slices.Contains(perms.Users, user.UserId) {
			return true
		}
		for _, role := range user.Roles {
			if slices.Contains(perms.Roles, role) {
				return true
			}
		}
	}
	return false
}

// toBytes converts the Resource object to its byte representation.
// It acquires a read lock on the mutex to ensure thread safety.
// It then marshals the object to JSON using the json.Marshal function.
// If an error occurs during marshaling, it panics.
// Finally, it returns the marshaled data as a byte slice.
func (r *Resource) toBytes() []byte {
	r.mu.RLock()
	defer r.mu.RUnlock()
	data, err := json.Marshal(r)
	if err != nil {
		panic(err)
	}
	return data
}

// IsOwner checks if the user present in the context is one of the owners of the resource.
// It returns true if the user is an owner, false otherwise.
func (r *Resource) IsOwner(ctx context.Context) bool {
	if user := ctx.Value(appcontext.CurrentUserKey).(*User); user != nil {
		if _, found := r.Owners[user.UserId]; found {
			return true
		}
	}
	return false
}

// WantTo determines if the user is able to perform the specified operation on the resource.
// It accepts the operation to be performed and the context containing the user information.
// If the resource mode is set to AnonymousMode, it allows any operation.
// If the resource mode is set to AuthenticatedMode, it only allows owners to perform the Owner operation.
// If the user is not authenticated, it denies all operations.
// If the user is an admin, it allows any operation.
// It also checks if the user is an owner of the resource.
// If the user is not an owner, it recursively checks if the user has the permission through any shares.
// It returns true if the user is able to perform the operation, otherwise returns false.
func (r *Resource) WantTo(operation Operation, ctx context.Context) bool {
	user, _ := ctx.Value(appcontext.CurrentUserKey).(*User)

	// when the mode is anonymous, anyone can perform the operation
	if r.Mode == AnonymousMode {
		return true
	}

	// if user is not authenticated return false immediately
	if user == nil && r.Mode != AnonymousMode {
		return false
	}

	isUserOwner := r.IsOwner(ctx)
	// when the mode is authenticated, only the owners can make changes
	if r.Mode == AuthenticatedMode && (isUserOwner || operation != Owner) {
		return true
	}

	// if user is admin they can perform the operation
	if user.IsAdmin() {
		return true
	}

	r.mu.RLock()
	defer r.mu.RUnlock()

	// check if the user is in the owners list
	if _, found := r.Owners[user.UserId]; found {
		return true
	}

	// check if there exists any share which allows the user to perform the operation
	for _, share := range r.Shares {
		if share.WantTo(operation, ctx) {
			return true
		}
	}

	return false
}

// fmtError formats an error message indicating that the given operation is not allowed by the current context.
// The operation parameter represents the specific operation that is not allowed.
// It returns an error with the formatted error message.
func fmtError(operation Operation) error {
	return fmt.Errorf("operation %s not allowed by current context", operation)
}

// Grant grants a share to a resource if the user has the required permission.
// If the user wants to grant SHARE_PLUS permission and has the required permission, the Share is appended to the Resource's Shares.
// If the user doesn't have the required permission, it returns an error using fmtError(SharePlus).
func (r *Resource) Grant(share Share, ctx context.Context) error {
	if r.WantTo(SharePlus, ctx) {
		r.mu.Lock()
		defer r.mu.Unlock()
		r.Shares = append(r.Shares, share)
		return nil
	}
	return fmtError(SharePlus)
}

// GrantUser grants the specified user a given operation on the resource.
func (r *Resource) GrantUser(user UserId, operation Operation, ctx context.Context) error {
	if !r.WantTo(SharePlus, ctx) {
		return fmtError(SharePlus)
	}
	r.mu.Lock()
	defer r.mu.Unlock()
	r.Shares = updateUserPermission(user, operation, r.Shares)
	return nil
}

func updateUserPermission(user UserId, operation Operation, shares []Share) []Share {
	for i := range shares {
		if u, ok := shares[i].(UserShare); ok && u.UserId == user {
			u.AllowedOperations[operation] = struct{}{}
			return shares
		}
	}
	shares = append(shares, UserShare{
		UserId: user,
		Permissions: Permissions{
			AllowedOperations: map[Operation]struct{}{
				operation: {},
			},
		},
	})
	return shares
}

// GrantRole grants a specific role with a given operation to the resource.
// If the resource does not want to allow sharing with the SharePlus operation, it returns an error.
// The method locks the resource before modifying the shares and releases the lock afterwards.
// If a RoleShare with the same role already exists, it adds the operation to its AllowedOperations and returns nil.
// If there is no RoleShare with the same role, it appends a new RoleShare to the Shares slice and sets its AllowedOperations to include the operation.
// The method then returns nil.
// Example usage:
// role := Role("admin")
// operation := Operation("create")
// ctx := context.Background()
// resource := &Resource{}
// err := resource.GrantRole(role, operation, ctx)
//
//	if err != nil {
//		log.Fatalf("failed to grant role: %v", err)
//	}
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

// RevokeUser revokes the user's share from the Resource in the key-value store.
// It checks if the user wants to perform the ShareMinus operation in the given context.
// The function acquires a lock on the Resource's mutex and releases it before returning.
// It creates a new slice to hold the updated shares, iterating through the existing shares and excluding the user's share by ID.
// Finally, it updates the Resource's Shares field with the new slice.
// It returns nil if the share is successfully revoked, otherwise it returns an error.
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

// RevokeRole removes the specified role from the Resource's shares. It returns an error if the user does not have the
// permission to revoke roles or if the role to be revoked does not exist
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
