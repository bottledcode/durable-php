package lib

import (
	"context"
	"github.com/nats-io/nats.go/jetstream"
	"go.uber.org/zap"
	"time"
)

func lockSubject(ctx context.Context, subject *Subject, js jetstream.JetStream, logger *zap.Logger) {
	logger.Debug("Attempting to take lock", zap.String("Subject", subject.String()))
	kv, err := js.CreateOrUpdateKeyValue(ctx, jetstream.KeyValueConfig{
		Bucket: subject.Bucket(),
		TTL:    5 * time.Minute,
	})
	if err != nil {
		panic(err)
	}

	value, err := kv.Get(ctx, "lock")
	if err != nil || value.Value() == nil {
		// a lock is free
		logger.Debug("Freely taking lock", zap.String("Subject", subject.String()))
		_, err := kv.Create(ctx, "lock", []byte("locked"))
		if err != nil {
			lockSubject(ctx, subject, js, logger)
			return
		}
		logger.Debug("Successfully got lock")
		return
	}

	// is the value locked
	if string(value.Value()) == "locked" {
		logger.Debug("Currently waiting for lock", zap.String("Subject", subject.String()))
		// watch for updates
		watcher, err := kv.Watch(ctx, "lock", jetstream.ResumeFromRevision(value.Revision()+1))
		if err != nil {
			panic(err)
		}
		var update jetstream.KeyValueEntry
		for update == nil {
			update = <-watcher.Updates()
		}
		logger.Debug("Update detected", zap.Any("update", update))
		lockSubject(ctx, subject, js, logger)
		return
	}

	logger.Debug("Freely taking lock", zap.String("Subject", subject.String()))
	// looks like we can take the lock
	_, err = kv.Update(ctx, "lock", []byte("locked"), value.Revision())
	if err != nil {
		lockSubject(ctx, subject, js, logger)
		return
	}
	logger.Debug("Successfully got lock")
}

func unlockSubject(ctx context.Context, subject *Subject, js jetstream.JetStream, logger *zap.Logger) {
	logger.Debug("Unlocking", zap.String("Subject", subject.String()))
	kv, err := js.CreateOrUpdateKeyValue(ctx, jetstream.KeyValueConfig{
		Bucket: subject.Bucket(),
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
