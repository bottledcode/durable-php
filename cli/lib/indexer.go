package lib

import (
	"context"
	"encoding/json"
	"github.com/nats-io/nats.go/jetstream"
	"github.com/typesense/typesense-go/typesense"
	"github.com/typesense/typesense-go/typesense/api"
	"github.com/typesense/typesense-go/typesense/api/pointer"
	"go.uber.org/zap"
	"strings"
	"sync"
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
			mu := sync.Mutex{}
			batch := map[string]interface{}{}

			ticker := time.NewTicker(250 * time.Millisecond)
			defer ticker.Stop()

			go func() {
				for range ticker.C {
					mu.Lock()
					var documents []interface{}
					for _, value := range batch {
						documents = append(documents, value)
					}
					batch = make(map[string]interface{})
					mu.Unlock()
					if len(documents) == 0 {
						continue
					}

					_, err := collection.Documents().Import(ctx, documents, &api.ImportDocumentsParams{
						Action:    pointer.String("upsert"),
						BatchSize: pointer.Int(40),
					})
					if err != nil {
						logger.Warn("Failure uploading batch to index", zap.Error(err))
						continue
					}
				}
			}()

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

				info := info

				go func() {
					ctx, done := context.WithCancel(ctx)

					obj, err := GetObjectStore(Entity, js, ctx)
					if err != nil {
						logger.Warn("Unable to load state for entity", zap.Error(err))
						done()
						return
					}
					stateFileData, err := obj.GetBytes(ctx, info.Name)
					if err != nil {
						logger.Warn("Unable to load state for entity", zap.Error(err))
						done()
						return
					}

					var result map[string]interface{}
					err = json.Unmarshal(stateFileData, &result)
					if err != nil {
						logger.Warn("Unable to load state for entity", zap.String("id", info.Name), zap.Error(err))
						done()
						return
					}
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

					mu.Lock()
					batch[id.String()] = entityData
					mu.Unlock()

					done()
				}()
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

			logger.Info("Stopping indexing of orchestrations")

			watch.Stop()
		}()

		caughtUp := false
		go func() {
			mu := sync.Mutex{}
			batch := map[string]interface{}{}

			ticker := time.NewTicker(250 * time.Millisecond)
			defer ticker.Stop()

			go func() {
				for range ticker.C {
					mu.Lock()
					var documents []interface{}
					for _, value := range batch {
						documents = append(documents, value)
					}
					batch = make(map[string]interface{})
					mu.Unlock()
					if len(documents) == 0 {
						continue
					}

					_, err := collection.Documents().Import(ctx, documents, &api.ImportDocumentsParams{
						Action:    pointer.String("upsert"),
						BatchSize: pointer.Int(40),
					})
					if err != nil {
						logger.Warn("Failure uploading batch to index", zap.Error(err))
						continue
					}
				}
			}()

			for info := range watch.Updates() {
				if info == nil {
					caughtUp = true
					continue
				}

				if !caughtUp {
					continue
				}

				info := info

				go func() {
					var result map[string]interface{}
					err := json.Unmarshal(info.Value(), &result)
					if err != nil {
						logger.Warn("Unable to load state for orchestration", zap.String("id", info.Key()), zap.Error(err))
						return
					}

					id := ParseStateId(result["id"].(map[string]interface{})["id"].(string))
					oid, _ := id.toOrchestrationId()

					status := result["status"].(map[string]interface{})

					orchestrationData := struct {
						ExecutionId   string `json:"execution_id"`
						InstanceId    string `json:"instance_id"`
						CreatedAt     string `json:"created_at"`
						CustomStatus  string `json:"custom_status"`
						LastUpdatedAt string `json:"last_updated_at"`
						RuntimeStatus string `json:"runtime_status"`
						Id            string `json:"id"`
					}{
						ExecutionId:   oid.executionId,
						InstanceId:    oid.instanceId,
						Id:            id.String(),
						CreatedAt:     status["createdAt"].(string),
						CustomStatus:  status["customStatus"].(string),
						LastUpdatedAt: status["lastUpdated"].(string),
						RuntimeStatus: status["runtimeStatus"].(string),
					}

					mu.Lock()
					batch[info.Key()] = orchestrationData
					mu.Unlock()
				}()
			}
		}()
	}

	return nil
}
