package lib

import (
	"context"
	"durable_php/glue"
	"errors"
	"github.com/nats-io/nats.go/jetstream"
	"go.uber.org/zap"
	"time"
)

const (
	LockValue string = "locked"
	LockKey   string = "lock"
)

func acquireLock(ctx context.Context, subject *glue.Subject, kv jetstream.KeyValue, logger *zap.Logger) (bool, uint64) {
	value, err := kv.Get(ctx, LockKey)
	// not found or empty value
	if err != nil || string(value.Value()) == "" {
		logger.Debug("Freely taking lock", zap.String("Subject", subject.String()))
		revision, err := kv.Create(ctx, "lock", []byte(LockValue))
		// race to create value failed
		if err != nil {
			return false, 0
		}
		logger.Debug("Got lock", zap.String("Subject", subject.String()))
		return true, revision
	}

	return false, value.Revision()
}

func waitForLock(ctx context.Context, subject *glue.Subject, kv jetstream.KeyValue, logger *zap.Logger) bool {
	logger.Debug("Waiting for lock", zap.String("Subject", subject.String()))

	ok, revision := acquireLock(ctx, subject, kv, logger)
	if ok {
		return true
	}

	watcher, err := kv.Watch(ctx, LockKey, jetstream.ResumeFromRevision(revision))
	if err != nil {
		logger.Warn("Failed to wait for lock", zap.Error(err))
		return false
	}
	defer watcher.Stop()

	for {
		select {
		case <-ctx.Done():
			return false
		case updated := <-watcher.Updates():
			if updated != nil {
				ok, _ = acquireLock(ctx, subject, kv, logger)
				if ok {
					return true
				}
			}
		}
	}
}

func lockSubject(ctx context.Context, subject *glue.Subject, js jetstream.JetStream, logger *zap.Logger) (func() error, error) {
	logger.Debug("Attempting to take lock", zap.String("Subject", subject.String()))
	kv, err := js.CreateOrUpdateKeyValue(ctx, jetstream.KeyValueConfig{
		Bucket: subject.Bucket(),
		TTL:    5 * time.Minute,
	})
	if err != nil {
		panic(err)
	}

	if ok := waitForLock(ctx, subject, kv, logger); ok {
		return func() error {
			err := kv.Delete(ctx, LockKey)
			if err != nil {
				return err
			}
			return nil
		}, nil
	}

	return nil, errors.New("failed to get lock")
}
