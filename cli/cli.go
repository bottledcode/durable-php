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
	"net/http"
	"os"
	"path/filepath"
	"strings"
)

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

func findBootstrap(options map[string]string, logger *zap.Logger) string {
	bootstrap := options["bootstrap"]

	if options["bootstrap"] == "" {
		bootstrap = "src/bootstrap.php"
	}

	cwd, err := os.Getwd()
	if err != nil {
		logger.Error("Could not get the current working directory", zap.Error(err))
	} else {
		bootstrap = filepath.Join(cwd, bootstrap)
	}

	if _, err := os.Stat(bootstrap); err != nil {
		logger.Warn("Bootstrap file does not exist; default DI implementation used", zap.String("filename", bootstrap))
		return ""
	}

	return bootstrap
}

func execute(args []string, options map[string]string) int {
	logger := getLogger(options)

	nurl := ""
	if options["nats-server"] == "" {
		nurl = options["nats-server"]
	}

	if nurl == "" {
		s := test.RunServer(&server.Options{
			Host:           "localhost",
			Port:           4222,
			NoLog:          true,
			NoSigs:         true,
			JetStream:      true,
			MaxControlLine: 2048,
		})
		defer s.Shutdown()
		nurl = nats.DefaultURL
	}

	nopts := []nats.Option{
		nats.Compression(true),
		nats.RetryOnFailedConnect(true),
	}

	if options["jwt"] != "" && options["nkey"] != "" {
		nopts = append(nopts, nats.UserCredentials(options["jwt"], strings.Split(options["nkey"], ",")...))
	}

	ns, err := nats.Connect(nurl, nopts...)
	if err != nil {
		panic(err)
	}
	js, err := jetstream.New(ns)
	if err != nil {
		panic(err)
	}
	ctx := context.WithValue(context.Background(), "bootstrap", findBootstrap(options, logger))

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
		logger.Info("Starting activity consumer")
		go lib.BuildConsumer(stream, ctx, streamName, lib.Activity, logger, js)
	}

	if options["no-entities"] != "true" {
		logger.Info("Starting entity consumer")
		go lib.BuildConsumer(stream, ctx, streamName, lib.Entity, logger, js)
	}

	if options["no-orchestrations"] != "true" {
		logger.Info("Starting orchestration consumer")
		go lib.BuildConsumer(stream, ctx, streamName, lib.Orchestration, logger, js)
	}

	port := options["port"]
	if port == "" {
		port = "8080"
	}

	if options["no-api"] != "true" {
		lib.Startup(ctx, js, logger, port, streamName)
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
		fmt.Println("{{VERSION}}")

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

			var store lib.IdKind
			switch args[0] {
			case string(lib.Orchestration):
				store = lib.Orchestration

				if len(args) == 1 {
					kv, err := js.KeyValue(ctx, string(lib.Orchestration))
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
			case string(lib.Activity):
				store = lib.Activity
			case string(lib.Entity):
				store = lib.Entity
			default:
				panic(fmt.Errorf("invalid type: %s", args[0]))
			}

			objectStore, err := lib.GetObjectStore(store, js, ctx)
			if err != nil {
				panic(err)
			}

			writer := &lib.ConsumingResponseWriter{
				Data:    "",
				Headers: make(http.Header),
			}

			if len(args) == 1 {
				lib.OutputList(writer, objectStore)
				fmt.Println(writer.Data)
				return 0
			}

			var id *lib.StateId
			switch store {
			case lib.Entity:
				fallthrough
			case lib.Orchestration:
				id = lib.ParseStateId(fmt.Sprintf("%s:%s:%s", string(store), args[1], args[2]))
			case lib.Activity:
				id = lib.ParseStateId(fmt.Sprintf("%s:%s", string(lib.Activity), args[1]))
			}

			err = lib.OutputStatus(ctx, writer, id, js, logger)
			if err != nil {
				logger.Fatal("Failed to output state", zap.Error(err))
				return 1
			}
			fmt.Println(writer.Data)
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
		WithOption(cli.NewOption("jwt", "Use a jwt file for connecting").WithType(cli.TypeString).WithChar('j')).
		WithOption(cli.NewOption("nkey", "Use a nkey seed file for connecting").WithType(cli.TypeString).WithChar('n')).
		WithCommand(run).
		WithCommand(initCmd).
		WithCommand(version).
		WithCommand(inspect).
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
