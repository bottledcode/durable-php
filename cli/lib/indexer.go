package lib

import (
	"context"
	"encoding/json"
	"github.com/nats-io/nats.go/jetstream"
	"github.com/typesense/typesense-go/typesense"
	"go.uber.org/zap"
	"strings"
	"time"
)

func IndexerListen(ctx context.Context, config *Config, kind IdKind, js jetstream.JetStream, logger *zap.Logger) error {
	logger.Info("Starting indexer extension", zap.String("for", string(kind)), zap.Any("config", config.Extensions.Search))

	client := typesense.NewClient(typesense.WithServer(config.Extensions.Search.Url), typesense.WithAPIKey(config.Extensions.Search.Key))

	switch kind {
	case Entity:
		collection := client.Collection(config.Stream + "_entities")

		err := CreateEntityIndex(ctx, client, config)
		if err != nil {
			return err
		}

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
				// todo: except when re-indexing
				if info == nil {
					caughtUp = true
					continue
				}

				if !caughtUp || strings.HasPrefix(info.Name, "/") {
					continue
				}

				ctx, done := context.WithCancel(ctx)

				logger.Info("Indexing", zap.String("name", info.Name), zap.Any("headers", info.ObjectMeta.Headers))

				obj, err := GetObjectStore(Entity, js, ctx)
				if err != nil {
					logger.Warn("Unable to load state for entity", zap.Error(err))
					done()
					continue
				}
				stateFileData, err := obj.GetBytes(ctx, info.Name)
				if err != nil {
					logger.Warn("Unable to load state for entity", zap.Error(err))
					done()
					continue
				}

				var result map[string]interface{}
				err = json.Unmarshal(stateFileData, &result)
				if err != nil {
					logger.Warn("Unable to load state for entity", zap.String("id", info.Name), zap.Error(err))
					done()
					continue
				}
				logger.Warn("Got state loaded", zap.Any("id", result["id"]))
				id := ParseStateId(result["id"].(map[string]interface{})["id"].(string))
				eid, _ := id.toEntityId()

				entityData := struct {
					Id    string      `json:"id"`
					Name  string      `json:"name"`
					State interface{} `json:"state"`
				}{
					Id:    eid.id,
					Name:  eid.name,
					State: result["state"],
				}

				_, err = collection.Documents().Upsert(ctx, entityData)
				if err != nil {
					logger.Warn("Unable to index entity", zap.String("id", id.String()), zap.Error(err))
					done()
					continue
				}

				done()
			}
		}()
	case Orchestration:
		collection := client.Collection(config.Stream + "_orchestrations")

		err := CreateOrchestrationIndex(ctx, client, config)
		if err != nil {
			return err
		}

		obj, err := js.KeyValue(ctx, string(Orchestration))
		if err != nil {
			// key value doesn't exist yet, try again in a few minutes
			go func() {
				time.Sleep(time.Second)
				IndexerListen(ctx, config, kind, js, logger)
			}()
			return nil
		}

		watch, err := obj.WatchAll(ctx)
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
				if info == nil {
					caughtUp = true
					continue
				}

				if !caughtUp {
					continue
				}

				ctx, done := context.WithCancel(ctx)
				logger.Info("Indexing", zap.String("name", info.Key()))
				var result map[string]interface{}
				err := json.Unmarshal(info.Value(), &result)
				if err != nil {
					logger.Warn("Unable to load state for orchestration", zap.String("id", info.Key()), zap.Error(err))
					done()
					continue
				}

				id := ParseStateId(result["id"].(map[string]interface{})["id"].(string))
				oid, _ := id.toOrchestrationId()

				status := result["status"].(map[string]interface{})

				orchestrationData := struct {
					ExecutionId   string `json:"execution_id"`
					InstanceId    string `json:"instance_id"`
					StateId       string `json:"state_id"`
					CreatedAt     string `json:"created_at"`
					CustomStatus  string `json:"custom_status"`
					LastUpdatedAt string `json:"last_updated_at"`
					RuntimeStatus string `json:"runtime_status"`
				}{
					ExecutionId:   oid.executionId,
					InstanceId:    oid.instanceId,
					StateId:       id.String(),
					CreatedAt:     status["createdAt"].(string),
					CustomStatus:  status["customStatus"].(string),
					LastUpdatedAt: status["lastUpdated"].(string),
					RuntimeStatus: status["runtimeStatus"].(string),
				}

				_, err = collection.Documents().Upsert(ctx, orchestrationData)
				if err != nil {
					logger.Warn("Unable put index orchestration", zap.String("id", id.String()), zap.Error(err))
					done()
					continue
				}

				done()
			}
		}()
	}

	return nil
}
