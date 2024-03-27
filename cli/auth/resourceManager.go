package auth

import (
	"context"
	"durable_php/appcontext"
	"durable_php/glue"
	"github.com/modern-go/concurrent"
	"github.com/nats-io/nats.go"
	"github.com/nats-io/nats.go/jetstream"
	"go.uber.org/zap"
	"time"
)

var cache *concurrent.Map

type ResourceManager struct {
	kv jetstream.KeyValue
	js jetstream.JetStream
}

// GetResourceManager is a function that creates and returns a ResourceManager instance based on the provided context and JetStream stream.
// If the cache is nil, it initializes the cache variable as a concurrent map.
// It also creates or updates a key-value pair in the JetStream stream using the provided context and KeyValeConfig.
// It panics if an error occurs during the creation or update of the key-value pair.
// Finally, it returns a pointer to the created ResourceManager instance.
func GetResourceManager(ctx context.Context, stream jetstream.JetStream) *ResourceManager {
	if cache == nil {
		cache = &concurrent.Map{}
	}

	kv, err := stream.CreateOrUpdateKeyValue(ctx, jetstream.KeyValueConfig{
		Bucket:  "resources",
		Storage: jetstream.FileStorage,
	})
	if err != nil {
		panic(err)
	}

	return &ResourceManager{
		kv: kv,
		js: stream,
	}
}

// DiscoverResource is a method of the ResourceManager struct that is responsible for discovering a resource based on
// the provided context, state ID, logger, and preventCreation flag
func (r *ResourceManager) DiscoverResource(ctx context.Context, id *glue.StateId, logger *zap.Logger, preventCreation bool) (*Resource, error) {
	currentUser, _ := ctx.Value(appcontext.CurrentUserKey).(*User)

	data, err := r.kv.Get(ctx, id.ToSubject().String())

	if (err != nil || data == nil) && !preventCreation {
		// resource wasn't created yet, so we assume the user is creating the resource
		resource := NewResourcePermissions(currentUser, ExplicitMode)
		resource.kv = r.kv
		resource.id = id
		resource.revision = 0
		if resource.CanCreate(id, ctx, logger) {
			err = resource.Update(ctx, logger)
			if err != nil {
				return nil, err
			}

			if resource.Expires.After(time.Now()) {
				r.ScheduleDelete(ctx, resource, resource.Expires)
			}

			return resource, nil
		}
		return nil, fmtError("user cannot create resource")
	} else if (err != nil || data == nil) && preventCreation {
		return nil, fmtError("resource not found")
	}
	resource, err := FromBytes(data.Value())
	if err != nil {
		return nil, err
	}
	resource.kv = r.kv
	resource.id = id
	resource.revision = data.Revision()
	if resource.ApplyPerms(id, ctx, logger) {
		resource.Update(ctx, logger)
		// if this fails, that is ok
	}

	return resource, nil
}

// ScheduleDelete is a method of the ResourceManager struct that is responsible for scheduling the deletion of a
// resource based on the provided context, resource, and time. It deletes the resource from the key-value store and
// publishes a delete message to NATS JetStream with a delay specified by the provided time. The resource is identified
// by its ID, which is added to the message headers.
func (r *ResourceManager) ScheduleDelete(ctx context.Context, resource *Resource, at time.Time) {
	r.kv.Delete(ctx, resource.id.ToSubject().Bucket())

	headers := nats.Header{}
	headers.Add("Delay", at.Format(time.RFC3339))
	headers.Add(string(glue.HeaderStateId), resource.id.String())

	r.js.PublishMsg(ctx, &nats.Msg{
		Subject: resource.id.ToSubject().String() + ".delete",
		Header:  headers,
	})
}

// Delete is a method of the ResourceManager struct that is responsible for deleting a resource based on the provided
// context and resource object
func (r *ResourceManager) Delete(ctx context.Context, resource *Resource) {
	r.kv.Delete(ctx, resource.id.ToSubject().Bucket())

	headers := nats.Header{}
	headers.Add(string(glue.HeaderStateId), resource.id.String())

	r.js.PublishMsg(ctx, &nats.Msg{
		Subject: resource.id.ToSubject().String() + ".delete",
		Header:  headers,
	})
}
