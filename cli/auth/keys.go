package auth

import (
	"context"
	"durable_php/appcontext"
	"durable_php/config"
	"encoding/base64"
	"errors"
	"fmt"
	"github.com/golang-jwt/jwt/v4"
	"net/http"
	"strings"
	"time"
)

// Get the last secret key from the config
func getActiveKey(config *config.Config) ([]byte, error) {
	if len(config.Extensions.Authz.Secrets) == 0 {
		return nil, errors.New("no secrets given in config")
	}
	key := config.Extensions.Authz.Secrets[len(config.Extensions.Authz.Secrets)-1]
	decoded, err := base64.StdEncoding.DecodeString(key)
	if err != nil {
		return nil, err
	}
	return decoded, nil
}

// DecorateContextWithUser takes a context and a user object and decorates the context with the user information.
func DecorateContextWithUser(ctx context.Context, user *User) context.Context {
	if user == nil {
		return ctx
	}

	return context.WithValue(ctx, appcontext.CurrentUserKey, user)
}

// ExtractUser extracts user information from the Authorization token in the HTTP request header.
// It returns the user and a boolean indicating if the extraction was successful.
//
// It expects the token in the "Bearer" format in the Authorization header.
// The token is parsed using the jwt.Parse function, which verifies the token's signing method and validity.
//
// If the token is valid and the necessary claims are present, ExtractUser constructs a User struct
// with the UserId and Roles from the token claims.
//
// The getRoles function converts the roles from the token claims to a slice of Role types.
//
// Parameters:
// - r: The *http.Request containing the Authorization header with the token.
// - config: The *config.Config object containing the necessary configuration data.
//
// Returns:
// - user: A pointer to the extracted User object if the extraction was successful, otherwise nil.
// - ok: A boolean indicating if the extraction was successful (true) or not (false).
func ExtractUser(r *http.Request, config *config.Config) (user *User, ok bool) {
	tokenString := r.Header.Get("Authorization")
	if tokenString == "" {
		return nil, false
	}

	tokenParts := strings.SplitN(tokenString, " ", 2)
	if tokenParts[0] != "Bearer" {
		return nil, false
	}

	token, err := jwt.Parse(tokenParts[1], func(token *jwt.Token) (interface{}, error) {
		if token.Method.Alg() != jwt.SigningMethodHS256.Alg() {
			return nil, fmt.Errorf("unexpected signing method")
		}

		key, err := getActiveKey(config)
		if err != nil {
			return "", err
		}
		return key, nil
	}, jwt.WithValidMethods([]string{"HS256"}))
	if err != nil {
		return nil, false
	}

	getRoles := func(roles []interface{}) []Role {
		rolesSlice := make([]Role, len(roles))
		for i, r := range roles {
			rolesSlice[i] = Role(r.(string))
		}
		return rolesSlice
	}

	if claims, ok := token.Claims.(jwt.MapClaims); ok && token.Valid {
		userId, ok := claims["sub"].(string)
		if !ok {
			return nil, false
		}
		rol, ok := claims["roles"].([]interface{})
		if !ok {
			return nil, false
		}
		return &User{
			UserId: UserId(userId),
			Roles:  getRoles(rol),
		}, true
	}

	return nil, false
}

// CreateUser creates a new JWT token with the specified userID, roles, and configuration.
// The token is signed using the active secret key from the config.
// The token will expire in 72 hours and is valid starting from 5 minutes ago.
// Returns the signed token string or an error if the signing process fails.
func CreateUser(userId UserId, role []Role, config *config.Config) (string, error) {
	token := jwt.NewWithClaims(jwt.SigningMethodHS256, jwt.MapClaims{
		"sub":   userId,
		"exp":   time.Now().Add(72 * time.Hour).Unix(),
		"iat":   time.Now().Add(-5 * time.Minute).Unix(),
		"roles": role,
	})

	key, err := getActiveKey(config)
	if err != nil {
		return "", err
	}
	signedString, err := token.SignedString(key)
	if err != nil {
		return "", err
	}

	return signedString, nil
}
