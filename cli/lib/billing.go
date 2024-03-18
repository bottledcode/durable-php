package lib

import (
	"context"
	"durable_php/config"
	"durable_php/glue"
	"encoding/json"
	"fmt"
	"github.com/nats-io/nats.go/jetstream"
	"go.uber.org/zap"
	"time"
)

type BillingEvent struct {
	Id       string        `json:"id"`
	Duration time.Duration `json:"duration,omitempty"`
}

// StartBillingProcessor starts up a consumer on the history stream and waits for
// events that are billable events. It then fires a billing event to the billings stream.
func StartBillingProcessor(ctx context.Context, config *config.Config, js jetstream.JetStream, logger *zap.Logger) error {
	if !config.Extensions.Billing.Enabled {
		logger.Info("Billing events are disabled")
		return nil
	}

	consumer, err := js.CreateOrUpdateConsumer(ctx, config.Stream+"_history", jetstream.ConsumerConfig{
		Durable:     "billing-consumer",
		HeadersOnly: true,
	})
	if err != nil {
		return err
	}

	activityTracker, err := js.CreateOrUpdateKeyValue(ctx, jetstream.KeyValueConfig{
		Storage:     jetstream.MemoryStorage,
		Bucket:      "ActivityTracker",
		TTL:         1 * time.Hour,
		Compression: true,
	})
	if err != nil {
		return err
	}

	maybeSendActivityBilling := func(id *glue.StateId) {
		started, err := activityTracker.Get(ctx, id.ToSubject().String()+"_start")
		if err != nil {
			return
		}
		ended, err := activityTracker.Get(ctx, id.ToSubject().String()+"_end")
		if err != nil {
			return
		}

		start := time.Time{}
		end := time.Time{}
		err = start.UnmarshalText(started.Value())
		if err != nil {
			logger.Warn("Failed to parse start time", zap.String("id", id.String()), zap.Error(err))
			return
		}
		err = end.UnmarshalText(ended.Value())
		if err != nil {
			logger.Warn("Failed to parse end time", zap.String("id", id.String()), zap.Error(err))
			return
		}

		duration := end.Sub(start)
		event, err := json.Marshal(BillingEvent{Id: id.String(), Duration: duration})
		if err != nil {
			logger.Warn("Failed to create billing event", zap.String("id", id.String()), zap.Error(err))
		}

		_, err = js.Publish(ctx, fmt.Sprintf("billing.%s.activities.%s.duration", config.Stream, id.ToSubject().String()), event)
		if err != nil {
			logger.Warn("Failed to publish billing event", zap.Error(err))
			return
		}
	}

	entityRegistry, err := js.CreateOrUpdateKeyValue(ctx, jetstream.KeyValueConfig{
		Storage:     jetstream.FileStorage,
		Bucket:      "EntityRegistry",
		Compression: true,
	})

	consume, err := consumer.Consume(func(msg jetstream.Msg) {
		targetType := msg.Headers().Get(string(glue.HeaderTargetType))
		eventType := msg.Headers().Get(string(glue.HeaderEventType))
		id := glue.ParseStateId(msg.Headers().Get(string(glue.HeaderStateId)))
		nowBytes := []byte(msg.Headers().Get(string(glue.HeaderEmittedAt)))
		emittedBy := glue.ParseStateId(msg.Headers().Get(string(glue.HeaderEmittedBy)))

		switch targetType {
		case "Activity":
			switch eventType {
			case "ScheduleTask":
				// an activity has been started
				_, err := activityTracker.Put(ctx, id.ToSubject().String()+"_start", nowBytes)
				if err != nil {
					panic(err)
				}

				maybeSendActivityBilling(id)
			}
		case "Orchestration":
			switch eventType {
			case "StartExecution":
				event, err := json.Marshal(BillingEvent{Id: id.String()})
				if err != nil {
					logger.Warn("Failed to create billing event", zap.String("id", id.String()), zap.Error(err))
					return
				}
				_, err = js.Publish(ctx, fmt.Sprintf("billing.%s.orchestrations.%s.started", config.Stream, id.ToSubject().String()), event)
				if err != nil {
					logger.Warn("Failed to publish billing event", zap.String("id", id.String()), zap.Error(err))
					return
				}
			case "TaskCompleted":
				fallthrough
			case "TaskFailed":
				_, err := activityTracker.Put(ctx, emittedBy.ToSubject().String()+"_end", nowBytes)
				if err != nil {
					panic(err)
				}
				maybeSendActivityBilling(emittedBy)
			}
		case "Entity":
			switch eventType {
			case "AwaitResult":
				fallthrough
			case "RaiseEvent":
				_, err := entityRegistry.Get(ctx, id.ToSubject().String())
				if err != nil {
					_, _ = entityRegistry.Put(ctx, id.ToSubject().String(), []byte{1})
					event, err := json.Marshal(BillingEvent{Id: id.String()})
					if err != nil {
						logger.Warn("Failed to create billing event", zap.String("id", id.String()), zap.Error(err))
						return
					}
					_, err = js.Publish(ctx, fmt.Sprintf("billing.%s.entities.%s.started", config.Stream, id.ToSubject().String()), event)
					if err != nil {
						logger.Warn("Failed to publish billing event", zap.String("id", id.String()), zap.Error(err))
					}
				}
			case "TaskCompleted":
				fallthrough
			case "TaskFailed":
				_, err := activityTracker.Put(ctx, emittedBy.ToSubject().String()+"_end", nowBytes)
				if err != nil {
					panic(err)
				}
				maybeSendActivityBilling(emittedBy)
			}
		}

		err := msg.Ack()
		if err != nil {
			logger.Warn("Failed to ack historical message", zap.Error(err))
		}
	})
	if err != nil {
		return err
	}

	go func() {
		<-ctx.Done()
		consume.Drain()
	}()

	return nil
}
