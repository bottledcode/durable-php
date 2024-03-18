package glue

import (
	"context"
	"github.com/nats-io/nats.go/jetstream"
)

func GetObjectStore(kind IdKind, js jetstream.JetStream, ctx context.Context) (jetstream.ObjectStore, error) {

	obj, err := js.CreateOrUpdateObjectStore(ctx, jetstream.ObjectStoreConfig{
		Bucket: string(kind),
	})

	return obj, err
}