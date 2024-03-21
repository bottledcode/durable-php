package lib

import (
	"context"
	"durable_php/auth"
	"durable_php/config"
	"durable_php/glue"
	"encoding/json"
	"fmt"
	"github.com/nats-io/nats.go/jetstream"
	"go.uber.org/zap"
	"net/http"
	"runtime"
	"strings"
	"time"
)

func BuildConsumer(stream jetstream.Stream, ctx context.Context, config *config.Config, kind glue.IdKind, logger *zap.Logger, js jetstream.JetStream, rm *auth.ResourceManager) {
	logger.Debug("Creating consumer", zap.String("stream", config.Stream), zap.String("kind", string(kind)))

	consumer, err := stream.Consumer(ctx, config.Stream+"-"+string(kind))
	if err != nil {
		panic(err)
	}

	iter, err := consumer.Messages(jetstream.PullMaxMessages(1))
	if err != nil {
		panic(err)
	}
	sem := make(chan struct{}, runtime.NumCPU())
	for {
		sem <- struct{}{}
		go func() {
			defer func() {
				<-sem
			}()

			msg, err := iter.Next()
			if err != nil {
				panic(err)
			}

			meta, _ := msg.Metadata()
			headers := msg.Headers()

			if headers.Get(string(glue.HeaderDelay)) != "" && meta.NumDelivered == 1 {
				logger.Debug("Delaying message", zap.String("delay", msg.Headers().Get("Delay")), zap.Any("Headers", meta))
				schedule, err := time.Parse(time.RFC3339, msg.Headers().Get("Delay"))
				if err != nil {
					panic(err)
				}

				delay := time.Until(schedule)
				if err := msg.NakWithDelay(delay); err != nil {
					panic(err)
				}
				return
			}

			if strings.HasSuffix(msg.Subject(), ".delete") {
				id := glue.ParseStateId(msg.Headers().Get(string(glue.HeaderStateId)))
				err := glue.DeleteState(ctx, js, logger, id)
				if err != nil {
					panic(err)
				}
				return
			}

			ctx := getCorrelationId(ctx, nil, &headers)

			if err := processMsg(ctx, logger, msg, js, config, rm); err != nil {
				panic(err)
			}
		}()
	}
}

// processMsg is responsible for processing a message received from JetStream.
// It takes a logger, msg, and JetStream as parameters. Do not panic!
func processMsg(ctx context.Context, logger *zap.Logger, msg jetstream.Msg, js jetstream.JetStream, config *config.Config, rm *auth.ResourceManager) error {
	logger.Debug("Received message", zap.Any("msg", msg))

	// lock the Subject, if it is a lockable Subject
	id := glue.ParseStateId(msg.Headers().Get(string(glue.HeaderStateId)))
	if id.Kind == glue.Entity {
		lockSubject(ctx, id.ToSubject(), js, logger)
		defer unlockSubject(ctx, id.ToSubject(), js, logger)
	}

	ctx, cancelCtx := context.WithCancel(ctx)
	defer cancelCtx()

	// configure the current user
	currentUser := &auth.User{}
	b := msg.Headers().Get(string(glue.HeaderProvenance))
	err := json.Unmarshal([]byte(b), currentUser)
	if err != nil {
		logger.Warn("Failed to unmarshal event provenance",
			zap.Any("Provenance", msg.Headers().Get(string(glue.HeaderProvenance))),
			zap.Error(err),
		)
		currentUser = nil
	} else {
		ctx = auth.DecorateContextWithUser(ctx, currentUser)
	}

	if config.Extensions.Authz.Enabled {
		// extract the source operations
		sourceOps := strings.Split(msg.Headers().Get(string(glue.HeaderSourceOps)), ",")
		// retrieve the source
		sourceId := glue.ParseStateId(msg.Headers().Get(string(glue.HeaderEmittedBy)))
		if sourceR, err := rm.DiscoverResource(ctx, sourceId, logger, true); err != nil {
			for _, op := range sourceOps {
				if !sourceR.WantTo(auth.Operation(op), ctx) {
					// user isn't allowed to do this, so warn
					logger.Warn("User attempted to perform an unauthorized operation", zap.String("operation", op), zap.String("From", sourceId.Id), zap.String("To", id.Id), zap.String("User", string(currentUser.UserId)))
					msg.Ack()
					return nil
				}
			}
		}

		// extract the target operations
		targetOps := strings.Split(msg.Headers().Get(string(glue.HeaderTargetOps)), ",")
		shouldCreate := false
		for _, op := range targetOps {
			switch auth.Operation(op) {
			case auth.Signal:
				fallthrough
			case auth.Call:
				fallthrough
			case auth.Lock:
				fallthrough
			case auth.Output:
				shouldCreate = true
			}
		}

		resource, err := rm.DiscoverResource(ctx, id, logger, !shouldCreate)
		if err != nil {
			logger.Warn("User attempted to perform an unauthorized operation", zap.String("operation", "create"), zap.String("From", sourceId.Id), zap.String("To", id.Id), zap.String("User", string(currentUser.UserId)))
			msg.Ack()
			return nil
		}

		m := msg.Headers().Get(string(glue.HeaderMeta))
		var meta map[string]interface{}
		if m != "[]" {
			err = json.Unmarshal([]byte(m), &meta)
			if err != nil {
				return err
			}

			switch msg.Headers().Get(string(glue.HeaderEventType)) {
			case "RevokeRole":
				role := meta["role"].(string)

				err := resource.RevokeRole(auth.Role(role), ctx)
				if err != nil {
					return err
				}
				err = resource.Update(ctx, logger)
				if err != nil {
					return err
				}
				msg.Ack()
				return nil
			case "RevokeUser":
				user := meta["userId"].(string)
				err := resource.RevokeUser(auth.UserId(user), ctx)
				if err != nil {
					return err
				}
				err = resource.Update(ctx, logger)
				if err != nil {
					return err
				}
				msg.Ack()
				return nil
			case "ShareWithRole":
				role := meta["role"].(auth.Role)
				operations := meta["allowedOperations"].([]auth.Operation)

				for _, op := range operations {
					err := resource.GrantRole(role, op, ctx)
					if err != nil {
						return err
					}
				}
				err = resource.Update(ctx, logger)
				if err != nil {
					return err
				}
				msg.Ack()
				return nil
			case "ShareWithUser":
				role := meta["userId"].(auth.UserId)
				operations := meta["allowedOperations"].([]auth.Operation)

				for _, op := range operations {
					err := resource.GrantUser(role, op, ctx)
					if err != nil {
						return err
					}
				}
				err = resource.Update(ctx, logger)
				if err != nil {
					return err
				}
				msg.Ack()
				return nil
			case "ShareOwnership":
				userId := meta["userId"].(auth.UserId)
				err := resource.ShareOwnership(userId, true, ctx)
				if err != nil {
					return err
				}
				err = resource.Update(ctx, logger)
				if err != nil {
					return err
				}
				msg.Ack()
				return nil
			case "GiveOwnership":
				userId := meta["userId"].(auth.UserId)
				err := resource.ShareOwnership(userId, false, ctx)
				if err != nil {
					return err
				}
				err = resource.Update(ctx, logger)
				if err != nil {
					return err
				}
				msg.Ack()
				return nil
			}
		}

		for _, op := range targetOps {
			if !resource.WantTo(auth.Operation(op), ctx) {
				logger.Warn("User attempted to perform an unauthorized operation", zap.String("operation", op), zap.String("From", sourceId.Id), zap.String("To", id.Id), zap.String("User", string(currentUser.UserId)))
				msg.Ack()
				return nil
			}
		}
	}

	// get the object
	stateFile, update := glue.GetStateFile(id, js, ctx, logger)

	// call glue with the associated bits
	glu := glue.NewGlue(config.Bootstrap, glue.ProcessMessage, make([]any, 0), stateFile.Name())

	var headers = http.Header{}
	var env = make(map[string]string)
	headers.Add("X-Correlation-ID", ctx.Value("cid").(string))
	env["EVENT"] = string(msg.Data())
	env["STATE_ID"] = msg.Headers().Get(string(glue.HeaderStateId))

	msgs, headers, _ := glu.Execute(ctx, headers, logger, env, js, id)

	// now update the stored state, if this fails due to optimistic concurrency, we immediately nak and fail
	err = update()
	if err != nil {
		err := msg.Nak()
		if err != nil {
			return err
		}
		return nil
	}

	// now we send our messages before acknowledging
	for _, msg := range msgs {
		msg.Header.Add("Parent-Correlation-Id", ctx.Value("cid").(string))
		msg.Subject = fmt.Sprintf("%s.%s", config.Stream, msg.Subject)
		logger.Debug("Sending event", zap.String("subject", msg.Subject))
		_, err := js.PublishMsg(ctx, msg)
		if err != nil {
			return err
		}
	}

	// and finally, ack the message
	err = msg.Ack()
	if err != nil {
		return err
	}

	return nil
}
