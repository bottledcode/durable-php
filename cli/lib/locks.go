package lib

import (
	"context"
	"github.com/nats-io/nats.go/jetstream"
	"go.uber.org/zap"
	"time"
)

func lockSubject(subject *subject, js jetstream.JetStream, logger *zap.Logger) {
	ctx := context.Background()
	logger.Info("Attempting to take lock", zap.String("subject", subject.String()))
	kv, err := js.CreateOrUpdateKeyValue(ctx, jetstream.KeyValueConfig{
		Bucket: subject.String(),
		TTL:    5 * time.Minute,
	})
	if err != nil {
		panic(err)
	}

	value, err := kv.Get(ctx, "lock")
	if err != nil || value.Value() == nil {
		// a lock is free
		logger.Info("Freely taking lock", zap.String("subject", subject.String()))
		_, err := kv.Create(ctx, "lock", []byte("locked"))
		if err != nil {
			lockSubject(subject, js, logger)
			return
		}
		return
	}

	// is the value locked
	if string(value.Value()) == "locked" {
		logger.Info("Currently waiting for lock", zap.String("subject", subject.String()))
		// watch for updates
		watcher, err := kv.Watch(ctx, "lock", jetstream.ResumeFromRevision(value.Revision()+1))
		if err != nil {
			panic(err)
		}
		var update jetstream.KeyValueEntry
		for update == nil {
			update = <-watcher.Updates()
		}
		logger.Info("Update detected", zap.Any("update", update))
		lockSubject(subject, js, logger)
		return
	}

	logger.Info("Freely taking lock", zap.String("subject", subject.String()))
	// looks like we can take the lock
	_, err = kv.Update(ctx, "lock", []byte("locked"), value.Revision())
	if err != nil {
		lockSubject(subject, js, logger)
		return
	}
}

func unlockSubject(subject *subject, js jetstream.JetStream, logger *zap.Logger) {
	logger.Info("Unlocking", zap.String("subject", subject.String()))
	ctx := context.Background()
	kv, err := js.CreateOrUpdateKeyValue(ctx, jetstream.KeyValueConfig{
		Bucket: subject.String(),
		TTL:    5 * time.Minute,
	})
	if err != nil {
		panic(err)
	}
	_, err = kv.PutString(ctx, "lock", "unlocked")
	if err != nil {
		return
	}
}
