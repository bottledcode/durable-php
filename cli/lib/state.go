package lib

import (
	"context"
	"encoding/base64"
	"fmt"
	"github.com/nats-io/nats.go/jetstream"
	"os"
	"path/filepath"
	"strings"
)

func getLibraryDir(target string) (string, error) {
	dirs := []string{
		filepath.Join("src", "Glue", target),
		filepath.Join("vendor", "bottledcode", "src", "Glue", target),
	}

	for _, dir := range dirs {
		if _, err := os.Stat(dir); err == nil {
			return "/" + dir, nil
		}
	}

	return "", fmt.Errorf("target: %s no found in any src directory", target)
}

func GetStateJson(err error, obj jetstream.ObjectStore, ctx context.Context, id string) []byte {
	file, err := obj.GetString(ctx, id)
	if err != nil {
		panic(err)
	}
	body, err := base64.StdEncoding.DecodeString(file)
	if err != nil {
		panic(err)
	}
	return body
}

func GetRealIdFromHumanId(name string, id string) string {
	id = base64.StdEncoding.EncodeToString([]byte(name + "./." + id))
	id = strings.TrimRight(id, "=")
	return id
}

func GetRealNameFromEncodedName(name string) (string, string) {
	switch len(name) % 4 {
	case 2:
		name += "=="
	case 3:
		name += "="
	}
	data, err := base64.StdEncoding.DecodeString(name)
	if err != nil {
		panic(err)
	}

	name = string(data)
	name, id, found := strings.Cut(name, "./.")

	if !found {
		return name, ""
	}

	return name, id
}

func getObjectStoreName(subject string) string {
	return strings.Split(subject, ".")[1]
}

func getObjectStoreId(subject string) string {
	return strings.Split(subject, ".")[2]
}

func getObjectStore(kind string, js jetstream.JetStream, ctx context.Context) (jetstream.ObjectStore, error) {
	obj, err := js.CreateOrUpdateObjectStore(ctx, jetstream.ObjectStoreConfig{
		Bucket: kind,
	})

	return obj, err
}
