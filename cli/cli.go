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
	"context"
	"durable_php/auth"
	"durable_php/config"
	"durable_php/glue"
	di "durable_php/init"
	"durable_php/lib"
	"encoding/json"
	"fmt"
	"github.com/dunglas/frankenphp"
	"github.com/nats-io/nats-server/v2/server"
	"github.com/nats-io/nats-server/v2/test"
	"github.com/nats-io/nats.go"
	"github.com/nats-io/nats.go/jetstream"
	"github.com/teris-io/cli"
	"go.uber.org/zap"
	"go.uber.org/zap/zapcore"
	"io"
	"net/http"
	"os"
	"os/signal"
	"runtime"
	"runtime/pprof"
	"strings"
	"sync"
	"syscall"
	"time"
)

var version string

func getLogger(options map[string]string) *zap.Logger {
	atom := zap.NewAtomicLevel()
	if options["debug"] == "true" {
		atom.SetLevel(zap.DebugLevel)
	} else if options["verbose"] == "true" {
		atom.SetLevel(zap.InfoLevel)
	} else {
		atom.SetLevel(zap.WarnLevel)
	}

	config := zap.NewDevelopmentEncoderConfig()
	core := zapcore.NewCore(zapcore.NewConsoleEncoder(config), os.Stderr, atom)
	return zap.New(core)
}

func execute(args []string, options map[string]string) int {
	logger := getLogger(options)

	cfg, err := config.GetProjectConfig()
	if err != nil {
		panic(err)
	}
	cfg, err = config.ApplyOptions(cfg, options)
	if err != nil {
		panic(err)
	}

	boostrapNats := false

	if cfg.Nat.Internal {
		logger.Warn("Running in dev mode, all data will be deleted at the end of this")
		data, err := os.MkdirTemp("", "nats-state-*")
		if err != nil {
			panic(err)
		}

		defer os.RemoveAll(data)

		profile, err := os.CreateTemp("", "")
		if err != nil {
			panic(err)
		}
		err = pprof.StartCPUProfile(profile)
		if err != nil {
			panic(err)
		}

		go func() {
			sigs := make(chan os.Signal, 1)

			signal.Notify(sigs, syscall.SIGINT, syscall.SIGTERM)

			<-sigs

			pprof.StopCPUProfile()
			profile.Close()

			logger.Warn("Profile output", zap.String("Filename", profile.Name()))

			os.RemoveAll(data)
			os.Exit(0)
		}()

		s := test.RunServer(&server.Options{
			Host:           "localhost",
			Port:           4222,
			NoLog:          true,
			NoSigs:         true,
			JetStream:      true,
			MaxControlLine: 2048,
			StoreDir:       data,
			HTTPPort:       8222,
		})
		defer s.Shutdown()
		boostrapNats = true
	}

	nopts := []nats.Option{
		nats.Compression(true),
		nats.RetryOnFailedConnect(true),
	}

	if cfg.Nat.Jwt != "" && cfg.Nat.Nkey != "" {
		nopts = append(nopts, nats.UserCredentials(cfg.Nat.Jwt, cfg.Nat.Nkey))
	}

	if cfg.Nat.Tls.Ca != "" {
		nopts = append(nopts, nats.RootCAs(strings.Split(cfg.Nat.Tls.Ca, ",")...))
	}

	if cfg.Nat.Tls.KeyFile != "" {
		nopts = append(nopts, nats.ClientCert(cfg.Nat.Tls.ClientCert, cfg.Nat.Tls.KeyFile))
	}

	ns, err := nats.Connect(cfg.Nat.Url, nopts...)
	if err != nil {
		panic(err)
	}
	js, err := jetstream.New(ns)
	if err != nil {
		panic(err)
	}
	ctx := context.WithValue(context.Background(), "bootstrap", cfg.Bootstrap)

	if boostrapNats {
		stream, _ := js.CreateStream(ctx, jetstream.StreamConfig{
			Name:        cfg.Stream,
			Description: "Handles durable-php events",
			Subjects:    []string{cfg.Stream + ".>"},
			Retention:   jetstream.WorkQueuePolicy,
			Storage:     jetstream.FileStorage,
			AllowRollup: false,
			DenyDelete:  true,
			DenyPurge:   true,
		})
		_, _ = js.CreateStream(ctx, jetstream.StreamConfig{
			Name:        cfg.Stream + "_history",
			Description: "The history of the stream",
			Mirror: &jetstream.StreamSource{
				Name: cfg.Stream,
			},
			Retention:   jetstream.LimitsPolicy,
			AllowRollup: true,
			MaxAge:      7 * 24 * time.Hour,
			Discard:     jetstream.DiscardOld,
		})

		consumers := []string{
			string(glue.Activity),
			string(glue.Entity),
			string(glue.Orchestration),
		}

		for _, kind := range consumers {
			_, _ = stream.CreateConsumer(ctx, jetstream.ConsumerConfig{
				Durable:       cfg.Stream + "-" + kind,
				FilterSubject: cfg.Stream + "." + kind + ".>",
				AckPolicy:     jetstream.AckExplicitPolicy,
				AckWait:       5 * time.Minute,
			})
		}
	}

	stream, err := js.Stream(ctx, cfg.Stream)
	if err != nil {
		panic(err)
	}

	opts := []frankenphp.Option{frankenphp.WithNumThreads(runtime.NumCPU() * 2), frankenphp.WithLogger(logger)}

	if err := frankenphp.Init(opts...); err != nil {
		panic(err)
	}
	defer frankenphp.Shutdown()

	rm := auth.GetResourceManager(ctx, js)

	if options["no-activities"] != "true" {
		logger.Info("Starting activity consumer")
		go lib.BuildConsumer(stream, ctx, cfg, glue.Activity, logger, js, rm)
	}

	if options["no-entities"] != "true" {
		logger.Info("Starting entity consumer")
		go lib.BuildConsumer(stream, ctx, cfg, glue.Entity, logger, js, rm)
	}

	if options["no-orchestrations"] != "true" {
		logger.Info("Starting orchestration consumer")
		go lib.BuildConsumer(stream, ctx, cfg, glue.Orchestration, logger, js, rm)
	}

	if len(cfg.Extensions.Search.Collections) > 0 {
		for _, collection := range cfg.Extensions.Search.Collections {
			switch collection {
			case "entities":
				err := lib.IndexerListen(ctx, cfg, glue.Entity, js, logger)
				if err != nil {
					panic(err)
				}
			case "orchestrations":
				err := lib.IndexerListen(ctx, cfg, glue.Orchestration, js, logger)
				if err != nil {
					panic(err)
				}
			}
		}
	}

	if cfg.Extensions.Billing.Enabled {
		if cfg.Extensions.Billing.Listen {

			billings := sync.Map{}
			billings.Store("e", 0)
			billings.Store("o", 0)
			billings.Store("a", 0*time.Minute)
			billings.Store("ac", 0)

			var incrementInt func(key string, amount int)
			incrementInt = func(key string, amount int) {
				var old interface{}
				old, _ = billings.Load(key)
				if !billings.CompareAndSwap(key, old, old.(int)+1) {
					incrementInt(key, amount)
				}
			}

			var incrementDur func(key string, amount time.Duration)
			incrementDur = func(key string, amount time.Duration) {
				var old interface{}
				old, _ = billings.Load(key)
				if !billings.CompareAndSwap(key, old, old.(time.Duration)+amount) {
					incrementDur(key, amount)
				}
			}

			outputBillingStatus := func() {
				costC := func(num interface{}, basis int) float64 {
					return float64(num.(int)) * float64(basis) / 10_000_000
				}

				costA := func(dur interface{}, basis int) float64 {
					duration := dur.(time.Duration)
					seconds := duration.Seconds()
					return float64(basis) * seconds / 100_000
				}

				avg := func(dur interface{}, count interface{}) time.Duration {
					seconds := dur.(time.Duration).Seconds()
					return time.Duration(seconds/float64(count.(int))) * time.Second
				}

				e, _ := billings.Load("e")
				o, _ := billings.Load("o")
				ac, _ := billings.Load("ac")
				a, _ := billings.Load("a")

				ecost := costC(e, cfg.Extensions.Billing.Costs.Entities.Cost)
				ocost := costC(o, cfg.Extensions.Billing.Costs.Orchestrations.Cost)
				acost := costA(a, cfg.Extensions.Billing.Costs.Activities.Cost)

				logger.Warn("Billing estimate",
					zap.Any("launched entities", e),
					zap.String("entity cost", fmt.Sprintf("$%.2f", ecost)),
					zap.Any("launched orchestrations", o),
					zap.String("orchestration cost", fmt.Sprintf("$%.2f", ocost)),
					zap.Any("activity time", a),
					zap.Any("activities launced", ac),
					zap.Any("average activity time", avg(a, ac)),
					zap.String("activity cost", fmt.Sprintf("$%.2f", acost)),
					zap.String("total estimate", fmt.Sprintf("$%.2f", ecost+ocost+acost)),
				)
			}

			go func() {
				ticker := time.NewTicker(3 * time.Second)
				for range ticker.C {
					outputBillingStatus()
				}
			}()

			billingStream, err := js.CreateOrUpdateStream(ctx, jetstream.StreamConfig{
				Name: "billing",
				Subjects: []string{
					"billing." + cfg.Stream + ".>",
				},
				Storage:   jetstream.FileStorage,
				Retention: jetstream.LimitsPolicy,
				MaxAge:    7 * 24 * time.Hour,
			})
			if err != nil {
				panic(err)
			}

			entityConsumer, err := billingStream.CreateOrUpdateConsumer(ctx, jetstream.ConsumerConfig{
				Durable: "entityAggregator",
				FilterSubjects: []string{
					"billing." + cfg.Stream + ".entities.>",
				},
			})
			if err != nil {
				panic(err)
			}

			consume, err := entityConsumer.Consume(func(msg jetstream.Msg) {
				incrementInt("e", 1)
				msg.Ack()
			})
			if err != nil {
				panic(err)
			}
			defer consume.Drain()

			orchestrationConsumer, err := billingStream.CreateOrUpdateConsumer(ctx, jetstream.ConsumerConfig{
				Durable:       "orchestrationAggregator",
				FilterSubject: "billing." + cfg.Stream + ".orchestrations.>",
			})
			if err != nil {
				panic(err)
			}

			consume, err = orchestrationConsumer.Consume(func(msg jetstream.Msg) {
				incrementInt("o", 1)
				msg.Ack()
			})
			if err != nil {
				panic(err)
			}
			defer consume.Drain()

			activityConsumer, err := billingStream.CreateOrUpdateConsumer(ctx, jetstream.ConsumerConfig{
				Durable:       "activityAggregator",
				FilterSubject: "billing." + cfg.Stream + ".activities.>",
			})
			if err != nil {
				panic(err)
			}

			consume, err = activityConsumer.Consume(func(msg jetstream.Msg) {
				incrementInt("ac", 1)
				var ev lib.BillingEvent
				err := json.Unmarshal(msg.Data(), &ev)
				if err != nil {
					panic(err)
				}
				incrementDur("a", ev.Duration)
				msg.Ack()
			})
			if err != nil {
				panic(err)
			}
			defer consume.Drain()
		}

		err := lib.StartBillingProcessor(ctx, cfg, js, logger)
		if err != nil {
			panic(err)
		}
	}

	port := options["port"]
	if port == "" {
		port = "8080"
	}

	if options["no-api"] != "true" {
		lib.Startup(ctx, js, logger, port, cfg)
	} else {
		block := make(chan struct{})
		<-block
	}

	return 0
}

var env map[string]string

func main() {
	run := cli.NewCommand("run", "Starts a webserver and starts processing events").WithAction(execute)
	initCmd := cli.NewCommand("init", "Initialize a project").
		WithArg(cli.NewArg("Name", "The name of the project").
			WithType(cli.TypeString)).
		WithAction(func(args []string, options map[string]string) int {
			return di.Execute(args, options, getLogger(options))
		})
	version := cli.NewCommand("version", "The current version").WithAction(func(args []string, options map[string]string) int {
		fmt.Println(version)

		return 0
	})
	inspect := cli.NewCommand("inspect", "Inspect the state store").
		WithArg(cli.NewArg("type", "One of orchestration|activity|entity").WithType(cli.TypeString)).
		WithArg(cli.NewArg("name", "The name of the class").AsOptional().WithType(cli.TypeString)).
		WithArg(cli.NewArg("id", "The id of the type to inspect or leave empty to list all ids").AsOptional().WithType(cli.TypeString)).
		WithOption(cli.NewOption("format", "json is currently the only supported format").WithType(cli.TypeString)).
		WithOption(cli.NewOption("all", "Show even hidden states").WithType(cli.TypeBool)).
		WithAction(func(args []string, options map[string]string) int {
			logger := getLogger(options)
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

			ctx, cancel := context.WithCancel(context.Background())
			defer cancel()

			var store glue.IdKind
			switch args[0] {
			case string(glue.Orchestration):
				store = glue.Orchestration

				if len(args) == 1 {
					kv, err := js.KeyValue(ctx, string(glue.Orchestration))
					if err != nil {
						fmt.Println("[]")
						return 0
					}
					keys, err := kv.Keys(ctx)
					if err != nil {
						logger.Warn("Failure listing orchestrations", zap.Error(err))
						fmt.Println("[]")
						return 2
					}

					marshal, err := json.Marshal(keys)
					if err != nil {
						logger.Fatal("Failure marshalling keys to json")
						return 1
					}

					fmt.Println(string(marshal))
					return 0
				}
			case string(glue.Activity):
				store = glue.Activity
			case string(glue.Entity):
				store = glue.Entity
			default:
				panic(fmt.Errorf("invalid type: %s", args[0]))
			}

			objectStore, err := glue.GetObjectStore(store, js, ctx)
			if err != nil {
				panic(err)
			}

			writer := &glue.ConsumingResponseWriter{
				Data:    "",
				Headers: make(http.Header),
			}

			if len(args) == 1 {
				lib.OutputList(writer, objectStore)
				fmt.Println(writer.Data)
				return 0
			}

			var id *glue.StateId
			switch store {
			case glue.Entity:
				fallthrough
			case glue.Orchestration:
				id = glue.ParseStateId(fmt.Sprintf("%s:%s:%s", string(store), args[1], args[2]))
			case glue.Activity:
				id = glue.ParseStateId(fmt.Sprintf("%s:%s", string(glue.Activity), args[0]))
			}

			ctx, cancel = context.WithCancel(ctx)

			res, _ := glue.GetStateFile(id, js, ctx, logger)
			res, err = os.Open(res.Name())
			defer res.Close()

			out, err := io.ReadAll(res)
			if err != nil {
				panic(err)
			}
			fmt.Println(string(out))
			cancel()
			return 0
		})
	createUser := cli.NewCommand("create-user", "Create a new user").
		WithArg(cli.NewArg("id", "The user id to assign to the user").WithType(cli.TypeString)).
		WithOption(cli.NewOption("admin", "Create the user as an admin").WithType(cli.TypeBool)).
		WithAction(func(args []string, options map[string]string) int {
			cfg, err := config.GetProjectConfig()
			if err != nil {
				panic(err)
			}
			rol := []auth.Role{"user"}
			switch options["admin"] {
			case "true":
				rol = append(rol, "admin")
			}

			user, err := auth.CreateUser(auth.UserId(args[0]), rol, cfg)
			if err != nil {
				return 1
			}
			fmt.Println(user)

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
		WithOption(cli.NewOption("nats-server", "The server to connect to").WithType(cli.TypeString)).
		WithOption(cli.NewOption("bootstrap", "A file that initializes a container, otherwise one will be generated for you").WithChar('b').WithType(cli.TypeString)).
		WithOption(cli.NewOption("no-api", "Disable the api server").WithType(cli.TypeBool)).
		WithOption(cli.NewOption("verbose", "Enable info level logging").WithType(cli.TypeBool)).
		WithOption(cli.NewOption("debug", "Enable debug logging").WithType(cli.TypeBool)).
		WithCommand(run).
		WithCommand(initCmd).
		WithCommand(version).
		WithCommand(inspect).
		WithCommand(createUser).
		WithCommand(cli.NewCommand("composer", "Shim around composer.phar -- run dphp composer --help for composer help")).
		WithCommand(cli.NewCommand("exec", "Execute a php script")).
		WithAction(execute)

	if len(os.Args) > 1 && os.Args[1] == "composer" {
		if _, err := os.Stat("bin/composer.phar"); err != nil {
			getLogger(make(map[string]string)).Fatal("bin/composer.phar is missing")
		}

		code := frankenphp.ExecuteScriptCLI("bin/composer.phar", os.Args[1:])
		os.Exit(code)
	}

	if len(os.Args) > 1 && os.Args[1] == "exec" {
		code := frankenphp.ExecuteScriptCLI(os.Args[2], os.Args[2:])
		os.Exit(code)
	}

	os.Exit(app.Run(os.Args, os.Stdout))
}
