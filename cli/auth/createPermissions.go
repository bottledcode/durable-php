package auth

import "errors"

// Limits represents the permissions limits.
// Fields:
// - User: The maximum number of users.
// - Role: The maximum number of roles.
// - Global: The maximum number of global permissions.
type Limits struct {
	User   int `json:"user"`
	Role   int `json:"role"`
	Global int `json:"global"`
}

// CreatePermissions represents the permissions for creating a resource.
// Fields:
// - Mode: The mode of the permissions (anonymous, authenticated, explicit).
// - Limits: The limits for the permissions.
// - Users: The list of user IDs with explicit permissions.
// - Roles: The list of roles with explicit permissions.
// - TimeToLive: The TTL (Time to Live) for the permissions, in nanoseconds.
type CreatePermissions struct {
	Mode       Mode     `json:"mode"`
	Limits     Limits   `json:"limits"`
	Users      []UserId `json:"users"`
	Roles      []Role   `json:"roles"`
	TimeToLive uint64   `json:"ttl"`
}

func (cp *CreatePermissions) Validate() error {
	// Ensure TimeToLive is not negative or zero
	if cp.TimeToLive < 0 {
		return errors.New("timeToLive must be greater than zero")
	}

	// Ensure Mode is one of the acceptable values
	validModes := map[Mode]bool{
		AnonymousMode:     true,
		AuthenticatedMode: true,
		ExplicitMode:      true,
	}
	if !validModes[cp.Mode] {
		return errors.New("invalid mode")
	}

	return nil
}
