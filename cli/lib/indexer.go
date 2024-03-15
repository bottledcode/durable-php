package lib

import (
	"context"
	"github.com/nats-io/nats.go/jetstream"
	"go.uber.org/zap"
	"strings"
)

func IndexerListen(ctx context.Context, kind IdKind, js jetstream.JetStream, logger *zap.Logger) error {
	logger.Info("Starting indexer extension", zap.String("for", string(kind)))

	switch kind {
	case Entity:
		obs, err := GetObjectStore(kind, js, ctx)
		if err != nil {
			return err
		}
		watch, err := obs.Watch(ctx)
		if err != nil {
			return err
		}

		go func() {
			if ctx.Done() == nil {
				return
			}
			<-ctx.Done()

			logger.Info("Stopping indexing of entities")

			watch.Stop()
		}()

		caughtUp := false

		go func() {
			for info := range watch.Updates() {
				// loop until we catch up
				if info == nil {
					caughtUp = true
					continue
				}

				if !caughtUp || strings.HasPrefix(info.Name, "/") {
					continue
				}

				logger.Info("Indexing", zap.String("name", info.Name))
			}
		}()
	}

	return nil
}
