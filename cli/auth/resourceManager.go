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

func (r *ResourceManager) DiscoverResource(ctx context.Context, id *glue.StateId, logger *zap.Logger, preventCreation bool) (*Resource, error) {
	currentUser, _ := ctx.Value(appcontext.CurrentUserKey).(*User)

	data, err := r.kv.Get(ctx, id.ToSubject().Bucket())
	if err != nil && !preventCreation {
		// resource wasn't created yet, so we assume the user is creating the resource
		resource := NewResourcePermissions(currentUser, ExplicitMode)
		resource.kv = r.kv
		resource.id = id
		resource.revision = 0
		if resource.CanCreate(id, ctx, logger) {
			logger.Debug("kv put")
			_, err := r.kv.Put(ctx, id.ToSubject().Bucket(), resource.toBytes())
			if err != nil {
				return nil, err
			}

			if resource.Expires.After(time.Now()) {
				r.ScheduleDelete(ctx, resource, resource.Expires)
			}

			return resource, nil
		}
		return nil, fmtError("user cannot create resource")
	} else if err != nil && preventCreation {
		return nil, fmtError("resource not found")
	}
	resource := FromBytes(data.Value())
	resource.kv = r.kv
	resource.id = id
	resource.revision = data.Revision()
	if resource.ApplyPerms(id, ctx, logger) {
		logger.Debug("kv update")
		// if this fails, that is ok
		r.kv.Update(ctx, id.ToSubject().Bucket(), resource.toBytes(), data.Revision())
	}

	return resource, nil
}

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

func (r *ResourceManager) Delete(ctx context.Context, resource *Resource) {
	r.kv.Delete(ctx, resource.id.ToSubject().Bucket())

	headers := nats.Header{}
	headers.Add(string(glue.HeaderStateId), resource.id.String())

	r.js.PublishMsg(ctx, &nats.Msg{
		Subject: resource.id.ToSubject().String() + ".delete",
		Header:  headers,
	})
}
