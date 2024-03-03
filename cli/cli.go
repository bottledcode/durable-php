/*
 * Copyright ©2024 Robert Landers
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the “Software”), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is
 *  furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED “AS IS”, WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
 * IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY
 * CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT
 * OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE
 * OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

package main

import (
	"bufio"
	"bytes"
	"context"
	"fmt"
	"github.com/dunglas/frankenphp"
	"github.com/nats-io/nats.go"
	"github.com/nats-io/nats.go/jetstream"
	"github.com/teris-io/cli"
	"go.uber.org/zap"
	"io"
	"net/http"
	"net/url"
	"os"
	"runtime"
	"strings"
	"time"
)

var routerScript string = "/index.php"

type DummyLoggingResponseWriter struct {
	logger  zap.Logger
	isError bool
}

func (w *DummyLoggingResponseWriter) Header() http.Header {
	return http.Header{}
}

func (w *DummyLoggingResponseWriter) Write(b []byte) (int, error) {
	scanner := bufio.NewScanner(bytes.NewReader(b))
	for scanner.Scan() {
		if w.isError {
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

func buildConsumer(stream jetstream.Stream, ctx context.Context, streamName string, kind string, logger *zap.Logger) {
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
				delay, err := time.ParseDuration(msg.Headers().Get("Delay"))
				if err != nil {
					panic(err)
				}
				if err := msg.NakWithDelay(delay); err != nil {
					panic(err)
				}
				return
			}

			processMsg(logger, msg)
		}()
	}
}

func processMsg(logger *zap.Logger, msg jetstream.Msg) {
	logger.Info("Received message", zap.Any("msg", msg))

	writer := DummyLoggingResponseWriter{
		logger:  *logger,
		isError: false,
	}

	body := io.NopCloser(strings.NewReader(string(msg.Data())))
	u, _ := url.Parse("http://localhost/event/" + msg.Subject())

	req := &http.Request{
		Method:           "POST",
		URL:              u,
		Proto:            "http://",
		ProtoMajor:       0,
		ProtoMinor:       0,
		Header:           http.Header(msg.Headers()),
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
		Path:        routerScript,
		RawPath:     routerScript,
		OmitHost:    req.URL.OmitHost,
		ForceQuery:  req.URL.ForceQuery,
		RawQuery:    req.URL.RawQuery,
		Fragment:    req.URL.Fragment,
		RawFragment: req.URL.RawFragment,
	}

	request, err := frankenphp.NewRequestWithContext(req)
	if err != nil {
		panic(err)
	}

	logger.Info("Consuming message")
	if err := frankenphp.ServeHTTP(&writer, request); err != nil {
		panic(err)
	}

	logger.Info("Ack message")
	if err := msg.Ack(); err != nil {
		panic(err)
	}
}

func (w *DummyLoggingResponseWriter) WriteHeader(statusCode int) {
	if statusCode >= 500 {
		w.isError = true
	}
}

func execute(args []string, options map[string]string) int {
	logger, err := zap.NewDevelopment()
	if options["router"] != "" {
		routerScript = options["router"]
	}
	if err != nil {
		panic(err)
	}

	nurl := nats.DefaultURL
	if options["nats-server"] == "" {
		nurl = options["nats-server"]
	}

	ns, err := nats.Connect(nurl)
	if err != nil {
		panic(err)
	}
	js, err := jetstream.New(ns)
	if err != nil {
		panic(err)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()

	streamName := options["stream"]
	if streamName == "" {
		streamName = "test"
	}

	opts := []frankenphp.Option{frankenphp.WithNumThreads(32), frankenphp.WithLogger(logger)}

	if err := frankenphp.Init(opts...); err != nil {
		panic(err)
	}
	defer frankenphp.Shutdown()

	stream, err := js.CreateOrUpdateStream(ctx, jetstream.StreamConfig{
		Name:        streamName,
		Description: "Handles durable-php events",
		Subjects:    []string{streamName + ".>"},
		Retention:   jetstream.WorkQueuePolicy,
		Storage:     jetstream.FileStorage,
		AllowRollup: false,
	})
	if err != nil {
		panic(err)
	}

	if options["no-activities"] != "false" {
		go buildConsumer(stream, ctx, streamName, "activities", logger)
	}

	if options["no-entities"] != "false" {
		go buildConsumer(stream, ctx, streamName, "entities", logger)
	}

	if options["no-orchestrations"] != "false" {
		go buildConsumer(stream, ctx, streamName, "orchestrations", logger)
	}

	http.HandleFunc("/", func(writer http.ResponseWriter, request *http.Request) {
		// rewrite the request
		request.URL = &url.URL{
			Scheme:      request.URL.Scheme,
			Opaque:      request.URL.RequestURI(),
			User:        request.URL.User,
			Host:        request.URL.Host,
			Path:        routerScript,
			RawPath:     routerScript,
			OmitHost:    request.URL.OmitHost,
			ForceQuery:  request.URL.ForceQuery,
			RawQuery:    request.URL.RawQuery,
			Fragment:    request.URL.Fragment,
			RawFragment: request.URL.RawFragment,
		}

		logger.Info("Received request", zap.String("requestUri", request.URL.RequestURI()))

		req, err := frankenphp.NewRequestWithContext(request, frankenphp.WithRequestLogger(logger))
		if err != nil {
			panic(err)
		}

		if err := frankenphp.ServeHTTP(writer, req); err != nil {
			panic(err)
		}
	})

	port := options["port"]
	if port == "" {
		port = "8080"
	}

	logger.Fatal("server error", zap.Error(http.ListenAndServe(":"+port, nil)))
	return 0
}

func main() {
	run := cli.NewCommand("run", "Starts a webserver and starts processing events").WithAction(execute)
	init := cli.NewCommand("init", "Initialize a project").
		WithArg(cli.NewArg("Name", "The name of the project").
			WithType(cli.TypeString)).
		WithAction(func(args []string, options map[string]string) int {
			return 0
		})
	version := cli.NewCommand("version", "The current version").WithAction(func(args []string, options map[string]string) int {
		fmt.Println("{{VERSION}}")

		return 0
	})

	app := cli.New("Durable PHP").
		WithOption(cli.NewOption("port", "The port to listen to (default 8080)").
			WithChar('p').
			WithType(cli.TypeNumber)).
		WithOption(cli.NewOption("stream", "The stream to listen to events from").
			WithChar('s').
			WithType(cli.TypeString)).
		WithOption(cli.NewOption("no-activities", "Do not parse activities").WithType(cli.TypeBool)).
		WithOption(cli.NewOption("no-entities", "Do not parse entities").WithType(cli.TypeBool)).
		WithOption(cli.NewOption("no-orchestrations", "Do not parse orchestrations").WithType(cli.TypeBool)).
		WithOption(cli.NewOption("router", "The router script").WithType(cli.TypeString)).
		WithOption(cli.NewOption("nats-server", "The server to connect to").WithType(cli.TypeString))
	WithCommand(run).
		WithCommand(init).
		WithCommand(version).
		WithAction(execute)

	os.Exit(app.Run(os.Args, os.Stdout))
}
