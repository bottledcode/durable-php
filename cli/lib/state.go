package lib

import (
	"context"
	"encoding/base64"
	"github.com/nats-io/nats.go/jetstream"
	"strings"
)

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

func GetRealIdFromHumanId(id string) string {
	id = base64.StdEncoding.EncodeToString([]byte(id))
	id = strings.TrimRight(id, "=")
	return id
}

func GetRealNameFromEncodedName(name string) string {
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

	return string(data)
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
