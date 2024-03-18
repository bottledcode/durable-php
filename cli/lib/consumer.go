package lib

import (
	"context"
	"durable_php/config"
	"durable_php/glue"
	"fmt"
	"github.com/nats-io/nats.go/jetstream"
	"go.uber.org/zap"
	"net/http"
	"runtime"
	"time"
)

func BuildConsumer(stream jetstream.Stream, ctx context.Context, config *config.Config, kind glue.IdKind, logger *zap.Logger, js jetstream.JetStream) {
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

			ctx := getCorrelationId(ctx, nil, &headers)

			if err := processMsg(ctx, logger, msg, js, config); err != nil {
				panic(err)
			}
		}()
	}
}

// processMsg is responsible for processing a message received from JetStream.
// It takes a logger, msg, and JetStream as parameters. Do not panic!
func processMsg(ctx context.Context, logger *zap.Logger, msg jetstream.Msg, js jetstream.JetStream, config *config.Config) error {
	logger.Debug("Received message", zap.Any("msg", msg))

	// lock the Subject, if it is a lockable Subject
	id := glue.ParseStateId(msg.Headers().Get(string(glue.HeaderStateId)))
	if id.Kind == glue.Entity {
		lockSubject(ctx, id.ToSubject(), js, logger)
		defer unlockSubject(ctx, id.ToSubject(), js, logger)
	}

	ctx, cancelCtx := context.WithCancel(ctx)
	defer cancelCtx()

	// get the object
	stateFile, update := glue.GetStateFile(id, js, ctx, logger)

	// call glue with the associated bits
	glu := glue.NewGlue(config.Bootstrap, "processMsg", make([]any, 0), stateFile.Name())

	var headers = http.Header{}
	var env = make(map[string]string)
	headers.Add("X-Correlation-ID", ctx.Value("cid").(string))
	env["EVENT"] = string(msg.Data())
	env["STATE_ID"] = msg.Headers().Get(string(glue.HeaderStateId))

	msgs, headers, _ := glu.Execute(ctx, headers, logger, env, js, id)

	// now update the stored state, if this fails due to optimistic concurrency, we immediately nak and fail
	err := update()
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
