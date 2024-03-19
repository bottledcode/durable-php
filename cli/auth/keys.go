package auth

import (
	"context"
	"durable_php/appcontext"
	"durable_php/config"
	"encoding/base64"
	"fmt"
	"github.com/golang-jwt/jwt/v4"
	"net/http"
	"strings"
	"time"
)

func getActiveKey(config *config.Config) []byte {
	key := config.Extensions.Authz.Secrets[len(config.Extensions.Authz.Secrets)-1]
	decoded, err := base64.StdEncoding.DecodeString(key)
	if err != nil {
		panic(err)
	}
	return decoded
}

func DecorateContextWithUser(ctx context.Context, user *User) context.Context {
	return context.WithValue(ctx, appcontext.CurrentUserKey, user)
}

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

		return getActiveKey(config), nil
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
		userId := claims["sub"].(string)
		rol := claims["roles"].([]interface{})
		return &User{
			UserId: UserId(userId),
			Roles:  getRoles(rol),
		}, true
	}

	return nil, false
}

func CreateUser(userId UserId, role []Role, config *config.Config) (string, error) {
	token := jwt.NewWithClaims(jwt.SigningMethodHS256, jwt.MapClaims{
		"sub":   userId,
		"exp":   time.Now().Add(72 * time.Hour).Unix(),
		"iat":   time.Now().Add(-5 * time.Minute).Unix(),
		"roles": role,
	})

	signedString, err := token.SignedString(getActiveKey(config))
	if err != nil {
		return "", err
	}

	return signedString, nil
}
