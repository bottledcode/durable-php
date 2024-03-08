package lib

import (
	"context"
	"github.com/nats-io/nats.go/jetstream"
	"go.uber.org/zap"
	"net/http"
	"runtime"
	"time"
)

func BuildConsumer(stream jetstream.Stream, ctx context.Context, streamName string, kind IdKind, logger *zap.Logger, js jetstream.JetStream) {
	logger.Info("Creating consumer", zap.String("stream", streamName), zap.String("kind", string(kind)))

	consumer, err := stream.CreateOrUpdateConsumer(ctx, jetstream.ConsumerConfig{
		AckPolicy:      jetstream.AckExplicitPolicy,
		FilterSubjects: []string{streamName + "." + string(kind) + ".>"},
		Durable:        streamName + "-" + string(kind),
	})
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

			if msg.Headers().Get("Delay") != "" && meta.NumDelivered == 1 {
				logger.Info("Delaying message", zap.String("delay", msg.Headers().Get("Delay")), zap.Any("Headers", meta))
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

			if err := processMsg(ctx, logger, msg, js); err != nil {
				panic(err)
			}
		}()
	}
}

// processMsg is responsible for processing a message received from JetStream.
// It takes a logger, msg, and JetStream as parameters. Do not panic!
func processMsg(ctx context.Context, logger *zap.Logger, msg jetstream.Msg, js jetstream.JetStream) error {
	logger.Info("Received message", zap.Any("msg", msg))

	// lock the Subject, if it is a lockable Subject
	id := ParseStateId(msg.Headers().Get(string(HeaderStateId)))
	if id.kind == Entity || id.kind == Orchestration {
		lockSubject(id.toSubject(), js, logger)
		defer unlockSubject(id.toSubject(), js, logger)
	}

	ctx, cancelCtx := context.WithCancel(ctx)
	defer cancelCtx()

	// get the object
	stateFile := getStateFile(id, js, ctx, logger)

	// call glue with the associated bits
	glu := &glue{
		bootstrap: ctx.Value("bootstrap").(string),
		function:  "processMsg",
		input:     make([]any, 0),
		payload:   stateFile.Name(),
	}

	var headers = http.Header{}
	var env = make(map[string]string)
	headers.Add(string(HeaderStateId), msg.Headers().Get(string(HeaderStateId)))
	env["EVENT"] = string(msg.Data())

	msgs, headers, _ := glu.execute(ctx, headers, logger, env, js)

	// now update the stored state
	updateStateFile(id, stateFile, js, ctx, logger)

	// now we send our messages before acknowledging
	for _, msg := range msgs {
		_, err := js.PublishMsg(ctx, msg)
		if err != nil {
			return err
		}
	}

	// and finally, ack the message
	err := msg.Ack()
	if err != nil {
		return err
	}

	return nil
}
