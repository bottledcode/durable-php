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
	"durable_php/lib"
	"fmt"
	"github.com/dunglas/frankenphp"
	"github.com/nats-io/nats.go"
	"github.com/nats-io/nats.go/jetstream"
	"github.com/teris-io/cli"
	"go.uber.org/zap"
	"os"
	"strings"
	"time"
)

func setEnv(options map[string]string) {
	env = make(map[string]string)
	env["BOOTSTRAP_FILE"] = options["bootstrap"]
}

func execute(args []string, options map[string]string) int {
	logger, err := zap.NewDevelopment()
	if options["router"] != "" {
		lib.RouterScript = options["router"]
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
		go lib.BuildConsumer(stream, ctx, streamName, "activities", logger, js)
	}

	if options["no-entities"] != "true" {
		go lib.BuildConsumer(stream, ctx, streamName, "entities", logger, js)
	}

	if options["no-orchestrations"] != "true" {
		go lib.BuildConsumer(stream, ctx, streamName, "orchestrations", logger, js)
	}

	port := options["port"]
	if port == "" {
		port = "8080"
	}

	lib.Startup(js, logger, port, streamName)

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
		WithArg(cli.NewArg("name", "The name of the class").AsOptional().WithType(cli.TypeString)).
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

					fmt.Println(lib.GetRealNameFromEncodedName(name))
				}

				return 0
			}

			if len(args) < 3 {
				fmt.Println("Must specify a name and id")
				return 1
			}

			name := args[1]
			id := args[2]
			if !strings.HasPrefix(id, "/") {
				id = lib.GetRealIdFromHumanId(name, id)
			}
			body := lib.GetStateJson(err, obj, ctx, id)
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
