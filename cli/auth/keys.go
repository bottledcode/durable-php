package auth

import (
	"context"
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
	return context.WithValue(ctx, CurrentUserKey, user)
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

	if claims, ok := token.Claims.(jwt.MapClaims); ok && token.Valid {
		userId := claims["sub"].(UserId)
		rol := claims["rol"].([]Role)
		return &User{
			UserId: userId,
			Roles:  rol,
		}, true
	}

	return nil, false
}

func CreateUser(userId string, role []Role, config *config.Config) (string, error) {
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
