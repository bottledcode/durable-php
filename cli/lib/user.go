package lib

import (
	"fmt"
	"github.com/golang-jwt/jwt/v4"
	"net/http"
	"strings"
	"time"
)

type Role string

const (
	RoleAdmin Role = "admin"
	RoleUser  Role = "user"
)

type User struct {
	userId string
	role   Role
}

func getActiveKey(config *Config) string {
	return config.Extensions.Authz.Secrets[len(config.Extensions.Authz.Secrets)-1]
}

func IsAdmin(r *http.Request, config *Config) bool {
	user, err := ExtractUser(r, config)
	if err != nil || user == nil {
		return false
	}

	return user.role == RoleAdmin
}

func ExtractUser(r *http.Request, config *Config) (*User, error) {
	tokenString := r.Header.Get("Authorization")
	if tokenString == "" {
		return nil, nil
	}

	tokenParts := strings.SplitN(tokenString, " ", 2)
	if tokenParts[0] != "Bearer" {
		return nil, fmt.Errorf("authorization header is malformed")
	}

	token, err := jwt.Parse(tokenParts[1], func(token *jwt.Token) (interface{}, error) {
		if token.Method.Alg() != jwt.SigningMethodHS256.Alg() {
			return nil, fmt.Errorf("unexpected signing method")
		}

		return getActiveKey(config), nil
	}, jwt.WithValidMethods([]string{"HS256"}))
	if err != nil {
		return nil, err
	}

	if claims, ok := token.Claims.(jwt.MapClaims); ok && token.Valid {
		userId := claims["sub"]
		rol := claims["rol"]
		role := Role(rol.(string))
		return &User{
			userId: userId.(string),
			role:   role,
		}, nil
	}

	return nil, fmt.Errorf("failed to validate token")
}

func CreateUser(userId string, role Role, config *Config) (string, error) {
	token := jwt.NewWithClaims(jwt.SigningMethodHS256, jwt.MapClaims{
		"sub": userId,
		"exp": time.Now().Add(72 * time.Hour).Unix(),
		"iat": time.Now().Add(-5 * time.Minute).Unix(),
		"rol": string(role),
	})

	signedString, err := token.SignedString(getActiveKey(config))
	if err != nil {
		return "", err
	}

	return signedString, nil
}
