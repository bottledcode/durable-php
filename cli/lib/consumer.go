package lib

import (
	"bytes"
	"context"
	"encoding/base64"
	"fmt"
	"github.com/dunglas/frankenphp"
	"github.com/nats-io/nats.go"
	"github.com/nats-io/nats.go/jetstream"
	"go.uber.org/zap"
	"io"
	"net/http"
	"net/url"
	"os"
	"runtime"
	"time"
)

// todo: update to vendored src
var RouterScript string = "/src/Task.php"

func BuildConsumer(stream jetstream.Stream, ctx context.Context, streamName string, kind string, logger *zap.Logger, js jetstream.JetStream) {
	logger.Info("Creating consumer", zap.String("stream", streamName), zap.String("kind", kind))

	consumer, err := stream.CreateOrUpdateConsumer(ctx, jetstream.ConsumerConfig{
		AckPolicy:      jetstream.AckExplicitPolicy,
		FilterSubjects: []string{streamName + "." + kind + ".>"},
		Durable:        streamName + "-" + kind,
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
				logger.Info("Delaying message", zap.String("delay", msg.Headers().Get("Delay")), zap.Any("headers", meta))
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

			if err := processMsg(logger, msg, js); err != nil {
				panic(err)
			}
		}()
	}
}

// processMsg is responsible for processing a message received from JetStream.
// It takes a logger, msg, and JetStream as parameters. Do not panic!
func processMsg(logger *zap.Logger, msg jetstream.Msg, js jetstream.JetStream) error {
	logger.Info("Received message", zap.Any("msg", msg))

	// take a lock on the state bucket
	lockSubject(msg.Subject(), js, logger)
	defer unlockSubject(msg.Subject(), js, logger)

	osctx := context.Background()
	// load the state from object storage
	logger.Info("Creating object store for context")

	obj, err := getObjectStore(getObjectStoreName(msg.Subject()), js, osctx)
	if err != nil {
		return err
	}
	stateFile, err := os.CreateTemp("", getObjectStoreId(msg.Subject()))
	if err != nil {
		return err
	}
	defer os.Remove(stateFile.Name())
	if err := obj.GetFile(osctx, getObjectStoreId(msg.Subject()), stateFile.Name()); err != nil {
		file, err := os.OpenFile(stateFile.Name(), os.O_CREATE|os.O_APPEND, 0666)
		if err != nil {
			return err
		}
		file.Close()
	}
	logger.Info("Created state file for handoff", zap.String("file", stateFile.Name()))

	writer := &InternalLoggingResponseWriter{
		logger:  *logger,
		isError: false,
		events:  make([]*nats.Msg, 0),
		query:   make(chan []string),
	}

	bodyBuf := bytes.NewBufferString(string(msg.Data()) + "\n\n")
	body := io.NopCloser(bodyBuf)

	go func() {
		query := <-writer.query
		obj, err := getObjectStore(query[0], js, context.Background())
		if err != nil {
			panic(err)
		}
		stateFile, err := os.CreateTemp("", "state")
		if err != nil {
			panic(err)
		}
		_, err = obj.PutFile(context.Background(), stateFile.Name())

		bodyBuf.WriteString(base64.StdEncoding.EncodeToString([]byte(stateFile.Name())))
	}()

	u, _ := url.Parse("http://localhost/event/" + msg.Subject())

	headers := http.Header(msg.Headers())
	if headers == nil {
		headers = make(http.Header)
	}
	headers.Add("State-File", stateFile.Name())

	req := &http.Request{
		Method:           "POST",
		URL:              u,
		Proto:            "http://",
		ProtoMajor:       0,
		ProtoMinor:       0,
		Header:           headers,
		Body:             body,
		GetBody:          nil,
		ContentLength:    int64(len(msg.Data())),
		TransferEncoding: nil,
		Close:            false,
		Host:             msg.Subject(),
		Form:             nil,
		PostForm:         nil,
		MultipartForm:    nil,
		Trailer:          nil,
		RemoteAddr:       "",
		RequestURI:       "",
		TLS:              nil,
		Cancel:           nil,
		Response:         nil,
	}

	req.URL = &url.URL{
		Scheme:      req.URL.Scheme,
		Opaque:      req.URL.RequestURI(),
		User:        req.URL.User,
		Host:        req.URL.Host,
		Path:        RouterScript,
		RawPath:     RouterScript,
		OmitHost:    req.URL.OmitHost,
		ForceQuery:  req.URL.ForceQuery,
		RawQuery:    req.URL.RawQuery,
		Fragment:    req.URL.Fragment,
		RawFragment: req.URL.RawFragment,
	}

	request, err := frankenphp.NewRequestWithContext(req, frankenphp.WithRequestLogger(logger))
	if err != nil {
		return err
	}

	logger.Info("Consuming message")
	if err := frankenphp.ServeHTTP(writer, request); err != nil {
		return err
	}

	if writer.status >= 400 {
		logger.Error(fmt.Sprintf("Received error %d from Task", writer.status))
		err := msg.TermWithReason(fmt.Sprintf("Received error %d from Task", writer.status))
		if err != nil {
			return err
		}
		return nil
	}

	logger.Info("Events to send", zap.Int("count", len(writer.events)))

	// we want to fire buffered events before committing state, in case this event is replayed for whatever reason,
	// there is a good chance the refired events from whatever is executed will be caught by duplicate detection
	for _, event := range writer.events {
		logger.Info("Sending event", zap.String("subject", event.Subject))
		_, err := js.PublishMsg(osctx, event)
		if err != nil {
			return err
		}
	}

	// store the state into the object store
	status, err := obj.PutFile(osctx, stateFile.Name())
	if err != nil {
		return err
	}
	logger.Info("Wrote state to store", zap.Uint64("size", status.Size))

	oid := getObjectStoreId(msg.Subject())

	prev, _ := obj.GetInfo(osctx, oid)
	d, err := obj.AddLink(osctx, oid, status)
	logger.Info("linked", zap.Any("link", d), zap.String("now", stateFile.Name()))
	if err != nil {
		return err
	}
	if prev != nil {
		err := obj.Delete(osctx, prev.Opts.Link.Name)
		if err != nil {
			return err
		}
	}

	// now, even if the event is refired, we are protected from reprocessing the event
	if err := msg.Ack(); err != nil {
		return err
	}

	return nil
}
