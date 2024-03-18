package auth

import (
	"context"
	"slices"
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
	if user := ctx.Value(CurrentUserKey).(*User); user != nil && user.UserId == u.UserId {
		return u.Permissions.WantTo(operation, ctx)
	}

	return false
}

type RoleShare struct {
	Role Role `json:"role"`
	Permissions
}

func (r RoleShare) WantTo(operation Operation, ctx context.Context) bool {
	if user := ctx.Value(CurrentUserKey).(*User); user != nil && user.Is(r.Role) {
		return r.Permissions.WantTo(operation, ctx)
	}

	return false
}
