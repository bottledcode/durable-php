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
	"encoding/base64"
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

// todo: update to vendored src
var routerScript string = "/src/Task.php"

type DummyLoggingResponseWriter struct {
	logger  zap.Logger
	isError bool
	status  int
	events  []*nats.Msg
	query   chan []string
}

func (w *DummyLoggingResponseWriter) Header() http.Header {
	return http.Header{}
}

func (w *DummyLoggingResponseWriter) Write(b []byte) (int, error) {
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

func (w *DummyLoggingResponseWriter) WriteHeader(statusCode int) {
	if statusCode >= 500 {
		w.isError = true
	}
	w.status = statusCode
}

func buildConsumer(stream jetstream.Stream, ctx context.Context, streamName string, kind string, logger *zap.Logger, js jetstream.JetStream) {
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

func getLockableSubject(subject string) string {
	return strings.ReplaceAll(subject, ".", "_")
}

func lockSubject(subject string, js jetstream.JetStream, logger *zap.Logger) {
	ctx := context.Background()
	logger.Info("Attempting to take lock", zap.String("subject", subject))
	kv, err := js.CreateOrUpdateKeyValue(ctx, jetstream.KeyValueConfig{
		Bucket: getLockableSubject(subject),
		TTL:    5 * time.Minute,
	})
	if err != nil {
		panic(err)
	}

	value, err := kv.Get(ctx, "lock")
	if err != nil || value.Value() == nil {
		// a lock is free
		logger.Info("Freely taking lock", zap.String("subject", subject))
		_, err := kv.Create(ctx, "lock", []byte("locked"))
		if err != nil {
			lockSubject(subject, js, logger)
			return
		}
		return
	}

	// is the value locked
	if string(value.Value()) == "locked" {
		logger.Info("Currently waiting for lock", zap.String("subject", subject))
		// watch for updates
		watcher, err := kv.Watch(ctx, "lock", jetstream.ResumeFromRevision(value.Revision()+1))
		if err != nil {
			panic(err)
		}
		var update jetstream.KeyValueEntry
		for update == nil {
			update = <-watcher.Updates()
		}
		logger.Info("Update detected", zap.Any("update", update))
		lockSubject(subject, js, logger)
		return
	}

	logger.Info("Freely taking lock", zap.String("subject", subject))
	// looks like we can take the lock
	_, err = kv.Update(ctx, "lock", []byte("locked"), value.Revision())
	if err != nil {
		lockSubject(subject, js, logger)
		return
	}
}

func unlockSubject(subject string, js jetstream.JetStream, logger *zap.Logger) {
	logger.Info("Unlocking", zap.String("subject", subject))
	ctx := context.Background()
	kv, err := js.CreateOrUpdateKeyValue(ctx, jetstream.KeyValueConfig{
		Bucket: getLockableSubject(subject),
		TTL:    5 * time.Minute,
	})
	if err != nil {
		panic(err)
	}
	_, err = kv.PutString(ctx, "lock", "unlocked")
	if err != nil {
		return
	}
}

func getObjectStoreName(subject string) string {
	return strings.Split(subject, ".")[1]
}

func getObjectStoreId(subject string) string {
	return strings.Split(subject, ".")[2]
}

func getObjectStore(kind string, js jetstream.JetStream, ctx context.Context) (jetstream.ObjectStore, error) {
	obj, err := js.CreateOrUpdateObjectStore(ctx, jetstream.ObjectStoreConfig{
		Bucket: kind,
	})

	return obj, err
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

	writer := &DummyLoggingResponseWriter{
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
		Path:        routerScript,
		RawPath:     routerScript,
		OmitHost:    req.URL.OmitHost,
		ForceQuery:  req.URL.ForceQuery,
		RawQuery:    req.URL.RawQuery,
		Fragment:    req.URL.Fragment,
		RawFragment: req.URL.RawFragment,
	}

	request, err := frankenphp.NewRequestWithContext(req, frankenphp.WithRequestLogger(logger), frankenphp.WithRequestEnv(env))
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

func setEnv(options map[string]string) {
	env = make(map[string]string)
	env["BOOTSTRAP_FILE"] = options["bootstrap"]
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

	setEnv(options)

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

	if options["no-activities"] != "true" {
		go buildConsumer(stream, ctx, streamName, "activities", logger, js)
	}

	if options["no-entities"] != "true" {
		go buildConsumer(stream, ctx, streamName, "entities", logger, js)
	}

	if options["no-orchestrations"] != "true" {
		go buildConsumer(stream, ctx, streamName, "orchestrations", logger, js)
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

		req, err := frankenphp.NewRequestWithContext(request, frankenphp.WithRequestLogger(logger), frankenphp.WithRequestEnv(env))
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

var env map[string]string

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
	inspect := cli.NewCommand("inspect", "Inspect the state store").
		WithArg(cli.NewArg("type", "One of orchestration|activity|entity").WithType(cli.TypeString)).
		WithArg(cli.NewArg("id", "The id of the type to inspect or leave empty to list all ids").AsOptional().WithType(cli.TypeString)).
		WithOption(cli.NewOption("format", "json is currently the only supported format").WithType(cli.TypeString)).
		WithOption(cli.NewOption("all", "Show even hidden states").WithType(cli.TypeBool)).
		WithAction(func(args []string, options map[string]string) int {
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

			store := ""
			if args[0] == "orchestration" {
				store = "orchestrations"
			} else if args[0] == "activity" {
				store = "activities"
			} else if args[0] == "entity" {
				store = "entities"
			} else {
				panic(fmt.Errorf("Invalid type: %s", args[0]))
			}

			ctx := context.Background()

			obj, err := js.CreateOrUpdateObjectStore(ctx, jetstream.ObjectStoreConfig{
				Bucket: store,
			})
			if err != nil {
				panic(err)
			}

			if len(args) == 1 {
				list, err := obj.List(ctx)
				if err != nil {
					return 0
				}

				for _, entry := range list {
					name := entry.Name

					if strings.HasPrefix(name, "/") && options["all"] != "true" {
						continue
					} else if strings.HasPrefix(name, "/") && options["all"] == "true" {
						fmt.Println(name)
						continue
					}

					switch len(name) % 4 {
					case 2:
						name += "=="
					case 3:
						name += "="
					}
					data, err := base64.StdEncoding.DecodeString(name)
					if err != nil {
						panic(err)
					}

					fmt.Println(string(data))
				}

				return 0
			}

			id := args[1]
			if !strings.HasPrefix(id, "/") {
				id = base64.StdEncoding.EncodeToString([]byte(id))
				id = strings.TrimRight(id, "=")
			}
			file, err := obj.GetString(ctx, id)
			if err != nil {
				panic(err)
			}
			body, err := base64.StdEncoding.DecodeString(file)
			if err != nil {
				panic(err)
			}
			fmt.Println(string(body))

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
		WithOption(cli.NewOption("nats-server", "The server to connect to").WithType(cli.TypeString)).
		WithOption(cli.NewOption("bootstrap", "A file that initializes a container, otherwise one will be generated for you").WithChar('b').WithType(cli.TypeString)).
		WithCommand(run).
		WithCommand(init).
		WithCommand(version).
		WithCommand(inspect).
		WithAction(execute)

	os.Exit(app.Run(os.Args, os.Stdout))
}
