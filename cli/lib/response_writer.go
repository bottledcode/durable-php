package lib

import (
	"bufio"
	"bytes"
	"github.com/nats-io/nats.go"
	"go.uber.org/zap"
	"net/http"
	"strings"
)

type ConsumingResponseWriter struct {
	data    string
	headers http.Header
}

func (c *ConsumingResponseWriter) Header() http.Header {
	return c.headers
}

func (c *ConsumingResponseWriter) Write(b []byte) (int, error) {
	// read all bytes into c.data
	c.data += string(b)
	return len(b), nil
}

func (c *ConsumingResponseWriter) WriteHeader(statusCode int) {
}

type InternalLoggingResponseWriter struct {
	logger  zap.Logger
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
		if strings.HasPrefix(line, "EVENT~!~") {
			w.logger.Info("Detected event", zap.String("line", line))
			parts := strings.Split(line, "~!~")
			subject := parts[1]
			delay := parts[2]
			body := parts[3]

			msg := &nats.Msg{
				Subject: subject,
				Data:    []byte(body),
				Header:  make(nats.Header),
			}

			if delay != "" {
				msg.Header.Add("Delay", delay)
			}

			w.events = append(w.events, msg)
		} else if strings.HasPrefix(line, "QUERY~!~") {
			w.logger.Info("Performing Query", zap.String("line", line))
			parts := strings.Split(line, "~!~")
			w.query <- parts[1:]
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
