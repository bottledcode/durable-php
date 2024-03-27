package auth

import (
	"context"
	"durable_php/appcontext"
	"errors"
	"github.com/stretchr/testify/assert"
	"testing"
	"time"
)

func TestNewResourcePermissions(t *testing.T) {
	owner := &User{UserId: "12345"}
	noOwner := &User{UserId: ""}

	tests := []struct {
		name     string
		owner    *User
		mode     Mode
		expected *Resource
	}{
		{
			name:  "ResourceWithOwner",
			owner: owner,
			mode:  "read",
			expected: &Resource{
				Owners:  map[UserId]struct{}{"12345": {}},
				Shares:  []Share{},
				Mode:    "read",
				Expires: time.Now(),
			},
		},
		{
			name:  "ResourceWithNoOwner",
			owner: noOwner,
			mode:  "write",
			expected: &Resource{
				Owners:  map[UserId]struct{}{},
				Shares:  []Share{},
				Mode:    "write",
				Expires: time.Now(),
			},
		},
	}

	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			got := NewResourcePermissions(test.owner, test.mode)

			if !sameOwners(got.Owners, test.expected.Owners) {
				t.Errorf("Owners was %v; want %v;", got.Owners, test.expected.Owners)
			}

			if len(got.Shares) != len(test.expected.Shares) {
				t.Errorf("Shares length was %d; want %d;", len(got.Shares), len(test.expected.Shares))
			}

			if got.Mode != test.expected.Mode {
				t.Errorf("Mode was \"%v\"; want \"%v\";", got.Mode, test.expected.Mode)
			}

			// Use a reasonable time closeness threshold because we cannot ensure that
			// the generated and expected timestamps are identical
			timeDiff := got.Expires.Sub(test.expected.Expires)
			if timeDiff > time.Second || timeDiff < -time.Second {
				t.Errorf("Expires was %v; want %v;", got.Expires, test.expected.Expires)
			}
		})
	}
}

// sameOwners is a helper function to compare the owners maps.
// It allows for the order to be different.
func sameOwners(a, b map[UserId]struct{}) bool {
	if len(a) != len(b) {
		return false
	}
	for k := range a {
		if _, ok := b[k]; !ok {
			return false
		}
	}
	return true
}

func Test_Resource_ShareOwnership(t *testing.T) {
	tests := []struct {
		name    string
		current *User
		new     UserId
		keep    bool
		wantErr error
	}{
		{
			name:    "Share ownership with nil user",
			new:     "new-user",
			wantErr: errors.New("cannot share with unknown user"),
		},
		{
			name:    "Share ownership and keep current",
			current: &User{UserId: "current-user"},
			new:     "new-user",
			keep:    true,
		},
		{
			name:    "Share ownership and remove current",
			current: &User{UserId: "current-user"},
			new:     "new-user",
			keep:    false,
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			r := &Resource{
				Owners: make(map[UserId]struct{}),
			}

			if tt.current != nil {
				r.Owners[tt.current.UserId] = struct{}{}
			}

			err := r.ShareOwnership(tt.new, tt.current, tt.keep)
			assert.Equal(t, tt.wantErr, err)

			if err == nil {
				assert.Contains(t, r.Owners, tt.new)

				if tt.keep && tt.current != nil {
					assert.Contains(t, r.Owners, tt.current.UserId)
				} else if tt.current != nil {
					assert.NotContains(t, r.Owners, tt.current.UserId)
				}
			}
		})
	}
}

func TestResource_IsUserPermitted(t *testing.T) {

	// Arrange
	currentUser := &User{
		UserId: UserId("some-user"),
		Roles:  []Role{"some-role"},
	}

	tests := []struct {
		name     string
		mode     Mode
		perms    CreatePermissions
		want     bool
		withUser bool
	}{
		{
			name: "PermissionShouldBeTrueForAnonymousMode",
			mode: AnonymousMode,
			perms: CreatePermissions{
				Mode:       AnonymousMode,
				TimeToLive: 10,
			},
			want:     true,
			withUser: false,
		},
		{
			name: "PermissionShouldBeTrueForAuthenticatedMode",
			mode: AuthenticatedMode,
			perms: CreatePermissions{
				Mode:       AuthenticatedMode,
				TimeToLive: 10,
				Users:      []UserId{currentUser.UserId},
			},
			want:     true,
			withUser: true,
		},
		{
			name: "PermissionShouldBeFalseForUnauthenticatedUserInAuthenticatedMode",
			mode: AuthenticatedMode,
			perms: CreatePermissions{
				Mode:       AuthenticatedMode,
				TimeToLive: 10,
				Users:      []UserId{"some-other-user"},
			},
			want:     false,
			withUser: false,
		},
		{
			name: "PermissionShouldBeTrueForExplicitMode",
			mode: ExplicitMode,
			perms: CreatePermissions{
				Mode:       ExplicitMode,
				TimeToLive: 10,
				Users:      []UserId{currentUser.UserId},
			},
			want:     true,
			withUser: true,
		},
		{
			name: "PermissionShouldBeFalseForUnincludedUserInExplicitMode",
			mode: ExplicitMode,
			perms: CreatePermissions{
				Mode:       ExplicitMode,
				TimeToLive: 10,
				Users:      []UserId{"some-other-user"},
			},
			want:     false,
			withUser: true,
		},
	}

	ctx := context.WithValue(context.Background(), appcontext.CurrentUserKey, currentUser)

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {

			r := &Resource{
				Mode:    tt.mode,
				Expires: time.Now().Add(time.Duration(tt.perms.TimeToLive) * time.Nanosecond),
			}

			ctx2 := ctx

			if !tt.withUser {
				ctx2 = context.Background()
			}

			if got := r.isUserPermitted(tt.perms, ctx2); got != tt.want {
				t.Errorf("isUserPermitted() = %v, want %v", got, tt.want)
			}
		})
	}
}
