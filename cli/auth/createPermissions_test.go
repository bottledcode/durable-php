package auth

import (
	"testing"

	"github.com/stretchr/testify/assert"
)

func TestCreatePermissions_Validate(t *testing.T) {
	testCases := []struct {
		name      string
		input     CreatePermissions
		wantError bool
	}{
		{
			name: "InvalidTimeToLive",
			input: CreatePermissions{
				TimeToLive: 0,
			},
			wantError: true,
		},
		{
			name: "InvalidMode",
			input: CreatePermissions{
				Mode: "InvalidMode",
			},
			wantError: true,
		},
		{
			name: "ValidPermissions",
			input: CreatePermissions{
				Mode:       AnonymousMode,
				Limits:     Limits{},   // assume a valid value
				Users:      []UserId{}, // assume a valid value
				Roles:      []Role{},   // assume a valid value
				TimeToLive: 10,
			},
			wantError: false,
		},
	}

	for _, tc := range testCases {
		t.Run(tc.name, func(t *testing.T) {
			err := tc.input.Validate()
			if tc.wantError {
				assert.Error(t, err)
			} else {
				assert.NoError(t, err)
			}
		})
	}
}
