package lib

import (
	"context"
	"durable_php/appcontext"
	"durable_php/auth"
	"durable_php/config"
	"durable_php/glue"
	"durable_php/ids"
	"encoding/json"
	"fmt"
	"github.com/nats-io/nats.go/jetstream"
	"go.uber.org/zap"
	"net/http"
	"runtime"
	"strings"
	"time"
)

func BuildConsumer(stream jetstream.Stream, ctx context.Context, config *config.Config, kind ids.IdKind, logger *zap.Logger, js jetstream.JetStream, rm *auth.ResourceManager) {
	logger.Debug("Creating consumer", zap.String("stream", config.Stream), zap.String("kind", string(kind)))

	consumer, err := stream.Consumer(ctx, config.Stream+"-"+string(kind))
	if err != nil {
		panic(err)
	}

	iter, err := consumer.Messages(jetstream.PullMaxMessages(1), jetstream.WithMessagesErrOnMissingHeartbeat(false))
	if err != nil {
		panic(err)
	}
	// create backpressure only when we get too many messages at once
	messages := make(chan jetstream.Msg, runtime.NumCPU()*2)
	sem := make(chan struct{}, runtime.NumCPU()*2)

	// spawn a thread responsible for handling messages
	go func() {
		for {
			select {
			case msg := <-messages:
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
					id := ids.ParseStateId(msg.Headers().Get(string(glue.HeaderStateId)))
					err := glue.DeleteState(ctx, js, logger, id)
					if err != nil {
						panic(err)
					}
					return
				}

				ctx := getCorrelationId(ctx, nil, &headers)

				// spawn a thread to process the message, but rate limit
				go func() {
					sem <- struct{}{}
					defer func() {
						<-sem
					}()
					if err := processMsg(ctx, logger, msg, js, config, rm); err != nil {
						panic(err)
					}
				}()
			}
		}
	}()

	// a single threaded reader
	go func() {
		for {
			msg, err := iter.Next()
			if err != nil {
				panic(err)
			}

			messages <- msg
		}
	}()
}

// processMsg is responsible for processing a message received from JetStream.
// It takes a logger, msg, and JetStream as parameters. Do not panic!
func processMsg(ctx context.Context, logger *zap.Logger, msg jetstream.Msg, js jetstream.JetStream, config *config.Config, rm *auth.ResourceManager) error {
	logger.Debug("Received message", zap.Any("msg", msg))

	// lock the Subject, if it is a lockable Subject
	id := ids.ParseStateId(msg.Headers().Get(string(glue.HeaderStateId)))
	if id.Kind == ids.Entity {
		unlocker, err := lockSubject(ctx, id.ToSubject(), js, logger)
		if err != nil {
			return err
		}
		defer unlocker()
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

	// retrieve the source
	sourceId := ids.ParseStateId(msg.Headers().Get(string(glue.HeaderEmittedBy)))

	if config.Extensions.Authz.Enabled {
		// extract the source operations
		sourceOps := strings.Split(msg.Headers().Get(string(glue.HeaderSourceOps)), ",")
		// it isn't clear why we need to check the source, so the following is commented out
		/*
			if sourceR, err := rm.DiscoverResource(ctx, sourceId, logger, true); err != nil {
				if sourceR == nil {
					logger.Warn("User accessed missing object", zap.Any("operation", sourceOps), zap.String("from", sourceId.Id), zap.String("to", id.Id), zap.String("user", string(currentUser.UserId)))
					msg.Ack()
					return nil
				}

				for _, op := range sourceOps {
					if !sourceR.WantTo(auth.Operation(op), ctx) {
						// user isn't allowed to do this, so warn
						logger.Warn("User attempted to perform an unauthorized operation", zap.String("operation", op), zap.String("From", sourceId.Id), zap.String("To", id.Id), zap.String("User", string(currentUser.UserId)))
						msg.Ack()
						return nil
					}
				}
			}
		*/

		// extract the target operations
		targetOps := strings.Split(msg.Headers().Get(string(glue.HeaderTargetOps)), ",")
		preventCreation := true
		for _, op := range targetOps {
			switch auth.Operation(op) {
			case auth.Signal:
				fallthrough
			case auth.Call:
				fallthrough
			case auth.Lock:
				fallthrough
			case auth.Output:
				preventCreation = false
			}
		}

		resource, err := rm.DiscoverResource(ctx, id, sourceId, logger, preventCreation)
		if err != nil {
			logger.Warn("User attempted to perform an unauthorized operation", zap.String("operation", "create"), zap.String("From", sourceId.Id), zap.String("To", id.Id), zap.String("User", string(currentUser.UserId)))
			msg.Ack()
			return nil
		}
		if resource == nil {
			logger.Warn("User accessed missing object", zap.Any("operation", sourceOps), zap.String("from", sourceId.Id), zap.String("to", id.Id), zap.String("user", string(currentUser.UserId)))
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
				if !resource.WantTo(auth.ShareMinus, ctx) {
					logger.Warn("User attempted to perform an unauthorized operation", zap.String("operation", "revokeRole"), zap.String("From", sourceId.Id), zap.String("To", id.Id), zap.String("User", string(currentUser.UserId)))
					msg.Ack()
					return nil
				}
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
				if !resource.WantTo(auth.ShareMinus, ctx) {
					logger.Warn("User attempted to perform an unauthorized operation", zap.String("operation", "revokeUser"), zap.String("From", sourceId.Id), zap.String("To", id.Id), zap.String("User", string(currentUser.UserId)))
					msg.Ack()
					return nil
				}
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
				if !resource.WantTo(auth.SharePlus, ctx) {
					logger.Warn("User attempted to perform an unauthorized operation", zap.String("operation", "shareWithRole"), zap.String("From", sourceId.Id), zap.String("To", id.Id), zap.String("User", string(currentUser.UserId)))
					msg.Ack()
					return nil
				}
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
				if !resource.WantTo(auth.SharePlus, ctx) {
					logger.Warn("User attempted to perform an unauthorized operation", zap.String("operation", "shareWithUser"), zap.String("From", sourceId.Id), zap.String("To", id.Id), zap.String("User", string(currentUser.UserId)))
					msg.Ack()
					return nil
				}
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
				if !resource.WantTo(auth.Owner, ctx) {
					logger.Warn("User attempted to perform an unauthorized operation", zap.String("operation", "shareOwnership"), zap.String("From", sourceId.Id), zap.String("To", id.Id), zap.String("User", string(currentUser.UserId)))
					msg.Ack()
					return nil
				}
				userId := meta["userId"].(auth.UserId)
				user := ctx.Value(appcontext.CurrentUserKey).(*auth.User)
				err := resource.ShareOwnership(userId, user, true)
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
				if !resource.WantTo(auth.Owner, ctx) {
					logger.Warn("User attempted to perform an unauthorized operation", zap.String("operation", "giveOwnership"), zap.String("From", sourceId.Id), zap.String("To", id.Id), zap.String("User", string(currentUser.UserId)))
					msg.Ack()
					return nil
				}
				userId := meta["userId"].(auth.UserId)
				user := ctx.Value(appcontext.CurrentUserKey).(*auth.User)
				err := resource.ShareOwnership(userId, user, true)
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
	env["REMOTE_ADDR"] = msg.Headers().Get("Remote-Addr")

	res, err := rm.DiscoverResource(ctx, id, sourceId, logger, true)
	if err != nil {
		logger.Error("DiscoverResource", zap.Error(err))
		panic(err)
	}
	if res != nil {
		ac, _ := rm.ToAuthContext(ctx, res)
		headers.Add("DPHP_AUTH_CONTEXT", string(ac))
	}

	msgs, headers, _, deleteAfter := glu.Execute(ctx, headers, logger, env, js, id, sourceId)

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

	if deleteAfter {
		resource, err := rm.DiscoverResource(ctx, id, sourceId, logger, false)
		if err != nil {
			return err
		}
		if resource == nil {
			return nil
		}

		rm.Delete(ctx, resource)
	}

	return nil
}
