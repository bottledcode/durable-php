package auth

import (
	"context"
	"durable_php/appcontext"
	"slices"
)

type KvType string
type Mode string
type Operation string

type UserId string
type Role string

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

// IsAdmin checks if the user has the "admin" role.
func (u *User) IsAdmin() bool {
	return u.Is("admin")
}

// Is checks if the user has the specified role.
func (u *User) Is(role Role) bool {
	return slices.Contains(u.Roles, role)
}

// Share represents an interface for sharing permissions on a resource.
type Share interface {
	// WantTo returns true if the user can perform the Operation
	WantTo(operation Operation, ctx context.Context) bool
}

// Permissions represents a set of allowed operations on a resource.
type Permissions struct {
	AllowedOperations map[Operation]struct{} `json:"allowedOperations"`
}

// WantTo checks if the specified operation is allowed based on the Permissions.
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

// WantTo checks if the user associated with the UserShare has the permission to perform the given operation.
// It retrieves the current user from the context and compares the user ID with the user ID of the UserShare.
// If the IDs match, it delegates the permission check to the WantTo method of the Permissions object of the UserShare.
// If the IDs do not match or if the user is nil, it returns false.
func (u UserShare) WantTo(operation Operation, ctx context.Context) bool {
	if user := ctx.Value(appcontext.CurrentUserKey).(*User); user != nil && user.UserId == u.UserId {
		return u.Permissions.WantTo(operation, ctx)
	}

	return false
}

type RoleShare struct {
	Role Role `json:"role"`
	Permissions
}

// WantTo checks if the user associated with the RoleShare has the given Role and if so, calls the WantTo method of the
// Permissions struct to check if the user is allowed to perform
func (r RoleShare) WantTo(operation Operation, ctx context.Context) bool {
	if user := ctx.Value(appcontext.CurrentUserKey).(*User); user != nil && user.Is(r.Role) {
		return r.Permissions.WantTo(operation, ctx)
	}

	return false
}
