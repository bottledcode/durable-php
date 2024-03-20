package glue

import (
	"bufio"
	"bytes"
	"context"
	"durable_php/appcontext"
	"encoding/json"
	"github.com/nats-io/nats.go"
	"go.uber.org/zap"
	"net/http"
	"strings"
	"time"
)

type GlueHeaders string

const (
	HeaderStateId    GlueHeaders = "State-Id"
	HeaderDelay      GlueHeaders = "Delay"
	HeaderEventType  GlueHeaders = "Event-Type"
	HeaderTargetType GlueHeaders = "Target-Type"
	HeaderEmittedBy  GlueHeaders = "Emitted-By"
	HeaderEmittedAt  GlueHeaders = "Emitted-At"
	HeaderProvenance GlueHeaders = "Provenance"
	HeaderTargetOps  GlueHeaders = "Target-Operations"
	HeaderSourceOps  GlueHeaders = "Source-Operations"
	HeaderMeta       GlueHeaders = "P-Meta"
)

type ConsumingResponseWriter struct {
	Data    string
	Headers http.Header
}

func (c *ConsumingResponseWriter) Header() http.Header {
	return c.Headers
}

func (c *ConsumingResponseWriter) Write(b []byte) (int, error) {
	// read all bytes into c.Data
	c.Data += string(b)
	return len(b), nil
}

func (c *ConsumingResponseWriter) WriteHeader(statusCode int) {
}

type InternalLoggingResponseWriter struct {
	logger    *zap.Logger
	isError   bool
	status    int
	events    []*nats.Msg
	query     chan []string
	headers   http.Header
	CurrentId *StateId
	Context   context.Context
}

func (w *InternalLoggingResponseWriter) Header() http.Header {
	return w.headers
}

func (w *InternalLoggingResponseWriter) Write(b []byte) (int, error) {
	scanner := bufio.NewScanner(bytes.NewReader(b))
	currentUser, err := json.Marshal(w.Context.Value(appcontext.CurrentUserKey))
	if err != nil {
		w.logger.Warn("Failed to create user for provenance events")
		currentUser = []byte("null")
	}
	for scanner.Scan() {
		line := scanner.Text()
		if after, found := strings.CutPrefix(line, "EVENT~!~"); found {
			w.logger.Debug("Detected event", zap.String("line", after))
			var body EventMessage
			err := json.Unmarshal([]byte(after), &body)
			if err != nil {
				return len(b), err
			}

			destinationId := ParseStateId(body.Destination)
			replyTo := ""
			if body.ReplyTo != "" {
				replyTo = ParseStateId(body.ReplyTo).ToSubject().String()
			}

			now, _ := time.Now().MarshalText()

			splitType := strings.Split(body.EventType, "\\")
			eventType := splitType[len(splitType)-1]

			header := make(nats.Header)
			header.Add(string(HeaderStateId), destinationId.String())
			header.Add(string(HeaderEventType), eventType)
			header.Add(string(HeaderTargetType), body.TargetType)
			header.Add(string(HeaderEmittedAt), string(now))
			header.Add(string(HeaderProvenance), string(currentUser))
			header.Add(string(HeaderTargetOps), body.TargetOps)
			header.Add(string(HeaderSourceOps), body.SourceOps)
			header.Add(string(HeaderMeta), body.Meta)
			if w.CurrentId != nil {
				header.Add(string(HeaderEmittedBy), w.CurrentId.String())
			}

			msg := &nats.Msg{
				Subject: destinationId.ToSubject().String(),
				Reply:   replyTo,
				Header:  header,
				Data:    []byte(body.Event),
			}

			if body.ScheduleAt.After(time.Now()) {
				msg.Header.Add(string(HeaderDelay), body.ScheduleAt.Format(time.RFC3339))
			}

			w.events = append(w.events, msg)
		} else if after, found := strings.CutPrefix(line, "QUERY~!~"); found {
			w.logger.Debug("Performing query", zap.String("line", after))
			w.query <- strings.Split(after, "~!~")
		} else if w.isError {
			w.logger.Error(scanner.Text())
		} else {
			w.logger.Info(scanner.Text())
		}
	}

	if err := scanner.Err(); err != nil {
		return 0, err
	}

	return len(b), nil
}

func (w *InternalLoggingResponseWriter) WriteHeader(statusCode int) {
	if statusCode >= 500 {
		w.isError = true
	}
	w.status = statusCode
}
