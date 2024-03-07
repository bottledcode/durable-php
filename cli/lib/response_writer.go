package lib

import (
	"bufio"
	"bytes"
	"encoding/json"
	"github.com/nats-io/nats.go"
	"go.uber.org/zap"
	"net/http"
	"strings"
	"time"
)

type GlueHeaders string

const (
	HeaderStateId GlueHeaders = "State-Id"
	HeaderDelay   GlueHeaders = "Delay"
)

type ConsumingResponseWriter struct {
	Data    string
	headers http.Header
}

func (c *ConsumingResponseWriter) Header() http.Header {
	return c.headers
}

func (c *ConsumingResponseWriter) Write(b []byte) (int, error) {
	// read all bytes into c.Data
	c.Data += string(b)
	return len(b), nil
}

func (c *ConsumingResponseWriter) WriteHeader(statusCode int) {
}

type InternalLoggingResponseWriter struct {
	logger  *zap.Logger
	isError bool
	status  int
	events  []*nats.Msg
	query   chan []string
}

func (w *InternalLoggingResponseWriter) Header() http.Header {
	return http.Header{}
}

func (w *InternalLoggingResponseWriter) Write(b []byte) (int, error) {
	scanner := bufio.NewScanner(bytes.NewReader(b))
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
				replyTo = ParseStateId(body.ReplyTo).toSubject().String()
			}

			header := make(nats.Header)
			header.Add(string(HeaderStateId), destinationId.String())

			msg := &nats.Msg{
				Subject: destinationId.toSubject().String(),
				Reply:   replyTo,
				Header:  header,
				Data:    []byte(body.Event),
			}

			if body.ScheduleAt.After(time.Now()) {
				msg.Header.Add(string(HeaderDelay), body.ScheduleAt.String())
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
