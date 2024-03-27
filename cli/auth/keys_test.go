package auth

import (
	"context"
	"durable_php/appcontext"
	"durable_php/config"
	"testing"
)

func TestGetActiveKey(t *testing.T) {
	testCases := []struct {
		name    string
		config  *config.Config
		wantErr bool
	}{
		{
			name: "Should return error when no secrets are available",
			config: &config.Config{
				Extensions: config.ExtensionsConfig{
					Authz: config.AuthzConfig{
						Secrets: []string{},
					},
				},
			},
			wantErr: true,
		},
		{
			name: "Should return error when secret encoding fails",
			config: &config.Config{
				Extensions: config.ExtensionsConfig{
					Authz: config.AuthzConfig{
						Secrets: []string{"invalidSecret"},
					},
				},
			},
			wantErr: true,
		},
		{
			name: "Should return key when valid secret",
			config: &config.Config{
				Extensions: config.ExtensionsConfig{
					Authz: config.AuthzConfig{
						Secrets: []string{"SGVsbG8sIHdvcmxkIQ=="}, // base64 encoded string of "Hello, world!"
					},
				},
			},
			wantErr: false,
		},
	}

	for _, tt := range testCases {
		t.Run(tt.name, func(t *testing.T) {
			_, err := getActiveKey(tt.config)

			if (err != nil) != tt.wantErr {
				t.Errorf("getActiveKey() error = %v, wantErr %v", err, tt.wantErr)
				return
			}
		})
	}
}

func TestDecorateContextWithUser(t *testing.T) {
	var tests = []struct {
		desc  string
		input *User
	}{
		{
			desc:  "decorating with nil user",
			input: nil,
		},
		{
			desc:  "decorating with valid user",
			input: &User{UserId: UserId("user"), Roles: []Role{"admin"}},
		},
	}

	for _, tt := range tests {
		t.Run(tt.desc, func(t *testing.T) {
			ctx := context.Background()
			ctx = DecorateContextWithUser(ctx, tt.input)

			if user, ok := ctx.Value(appcontext.CurrentUserKey).(*User); ok {
				if user.UserId != tt.input.UserId {
					t.Errorf("Expected User ID: %v, got: %v", tt.input.UserId, user.UserId)
				}
				for i, role := range user.Roles {
					if role != tt.input.Roles[i] {
						t.Errorf("Expected User Role: %v, got: %v", tt.input.Roles[i], role)
					}
				}
			} else if tt.input != nil {
				t.Error("Expected to find an User, but none was found.")
			}
		})
	}
}
