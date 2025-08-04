package ext

import "C"

import (
	"context"
	"encoding/json"
	"errors"
	"github.com/bottledcode/durable-php/cli/auth"
	"github.com/bottledcode/durable-php/cli/config"
	"github.com/bottledcode/durable-php/cli/ext/helpers"
	"github.com/bottledcode/durable-php/cli/glue"
	"github.com/bottledcode/durable-php/cli/ids"
	"github.com/bottledcode/durable-php/cli/lib"
	"github.com/dunglas/frankenphp"
	"github.com/nats-io/nats-server/v2/server"
	"github.com/nats-io/nats-server/v2/test"
	"github.com/nats-io/nats.go"
	"github.com/nats-io/nats.go/jetstream"
	"go.uber.org/zap"
	"os"
	"strings"
	"sync"
	"time"
	"unsafe"
)

/**
This is a php extension that operates as a client for durable php
*/

// export_php:namespace Bottledcode\DurablePhp\Ext

func go_shutdown_module() {
	os.RemoveAll(helpers.NatsState)
}

// export_php:module init
func go_init_module() {
	cfg, err := config.GetProjectConfig()
	if err != nil {
		panic(err)
	}
	helpers.Config = cfg

	helpers.Logger = helpers.GetLogger(zap.DebugLevel)
	logger := helpers.Logger

	logger.Info("Starting Durable PHP")

	boostrapNats := cfg.Nat.Bootstrap
	if cfg.Nat.Internal {
		logger.Warn("Running in dev mode, all data will be deleted at the end of this")
		helpers.NatsState, err = os.MkdirTemp("", "nats-state-*")
		if err != nil {
			panic(err)
		}

		s := test.RunServer(&server.Options{
			Host:           "localhost",
			Port:           4222,
			NoLog:          true,
			NoSigs:         true,
			JetStream:      true,
			MaxControlLine: 2048,
			StoreDir:       helpers.NatsState,
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
	helpers.Js, err = jetstream.New(ns)
	if err != nil {
		panic(err)
	}
	ctx := context.WithValue(context.Background(), "bootstrap", cfg.Bootstrap)

	if boostrapNats {
		stream, _ := helpers.Js.CreateStream(ctx, jetstream.StreamConfig{
			Name:        cfg.Stream,
			Description: "Handles durable-php events",
			Subjects:    []string{cfg.Stream + ".>"},
			Retention:   jetstream.WorkQueuePolicy,
			Storage:     jetstream.FileStorage,
			AllowRollup: false,
			DenyDelete:  true,
			DenyPurge:   true,
		})
		_, _ = helpers.Js.CreateStream(ctx, jetstream.StreamConfig{
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
			string(ids.Activity),
			string(ids.Entity),
			string(ids.Orchestration),
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

	if len(cfg.Extensions.Search.Collections) > 0 {
		for _, collection := range cfg.Extensions.Search.Collections {
			switch collection {
			case "entities":
				err := lib.IndexerListen(ctx, cfg, ids.Entity, helpers.Js, logger)
				if err != nil {
					cfg.Extensions.Search.Collections = []string{}
					logger.Warn("Disabling search extension due to failing to connect to typesense")
				}
			case "orchestrations":
				err := lib.IndexerListen(ctx, cfg, ids.Orchestration, helpers.Js, logger)
				if err != nil {
					cfg.Extensions.Search.Collections = []string{}
					logger.Warn("Disabling search extension due to failing to connect to typesense")
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

			/*
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
			*/

			billingStream, err := helpers.Js.CreateOrUpdateStream(ctx, jetstream.StreamConfig{
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

		err := lib.StartBillingProcessor(ctx, cfg, helpers.Js, logger)
		if err != nil {
			panic(err)
		}
	}
}

// Authorize the user to access the given event destination.
// (false, nil) means not found, while (true, nil) means they're allowed.
// Any error means the user is not authorised.
func Authorize(ctx context.Context, ev *glue.EventMessage, from *ids.StateId, preventCreation bool, operation auth.Operation) (bool, error) {
	if !helpers.Config.Extensions.Authz.Enabled {
		return true, nil
	}

	rm := auth.GetResourceManager(ctx, helpers.Js)
	r, err := rm.DiscoverResource(ctx, ids.ParseStateId(ev.Destination), from, helpers.Logger, preventCreation)
	if err != nil {
		err = errors.Join(errors.New("user is not authorised"), err)
		helpers.ThrowPHPException(err.Error())
		return false, err
	}
	if r == nil {
		return false, nil
	}

	if !r.WantTo(operation, ctx) {
		err = errors.New("user is not authorised")
		helpers.ThrowPHPException(err.Error())
		return false, err
	}

	return true, nil
}

// export_php:function get_string(): string
func get_string() unsafe.Pointer {
	return frankenphp.PHPString("a string", false)
}

// export_php:function emit_event(array $userContext, array $event, string $from): int
func emit_event(userVal *C.zval, event *C.zval, fromStr *C.zend_string) int64 {
	userArr := frankenphp.GoArray(unsafe.Pointer(userVal))
	user := helpers.GetUserContext(userArr)
	if user.UserId == "" || len(user.Roles) == 0 {
		helpers.ThrowPHPException("User context is missing userId or roles")
		return 0
	}

	from := ids.ParseStateId(frankenphp.GoString(unsafe.Pointer(fromStr)))

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	eventArr := frankenphp.GoArray(unsafe.Pointer(event))
	ev, err := helpers.ParseEvent(eventArr)
	if err != nil {
		helpers.ThrowPHPException(err.Error())
		return 0
	}

	operation := auth.Operation(ev.TargetOps)
	preventCreation := true
	switch operation {
	case auth.Lock:
		fallthrough
	case auth.Call:
		fallthrough
	case auth.Signal:
		fallthrough
	case auth.Output:
		preventCreation = false
	}

	authd, err := Authorize(ctx, ev, from, preventCreation, operation)
	if err != nil {
		helpers.ThrowPHPException(err.Error())
		return 0
	}
	if !authd {
		helpers.ThrowPHPException("Resource not found")
	}
	replyTo := ""
	if ev.ReplyTo != "" {
		replyTo = ids.ParseStateId(ev.ReplyTo).ToSubject().String()
	}

	splitType := strings.Split(ev.EventType, "\\")
	eventType := splitType[len(splitType)-1]

	destinationId := ids.ParseStateId(ev.Destination)

	now, err := time.Now().MarshalText()
	if err != nil {
		helpers.ThrowPHPException(err.Error())
		return 0
	}

	userJson, err := json.Marshal(user)
	if err != nil {
		helpers.ThrowPHPException(err.Error())
		return 0
	}

	header := make(nats.Header)
	header.Add(string(glue.HeaderStateId), destinationId.String())
	header.Add(string(glue.HeaderEventType), eventType)
	header.Add(string(glue.HeaderTargetType), ev.TargetType)
	header.Add(string(glue.HeaderEmittedAt), string(now))
	header.Add(string(glue.HeaderProvenance), string(userJson))
	header.Add(string(glue.HeaderTargetOps), ev.TargetOps)
	header.Add(string(glue.HeaderSourceOps), ev.SourceOps)
	header.Add(string(glue.HeaderMeta), ev.Meta)
	header.Add(string(glue.HeaderEmittedBy), from.String())

	msg := &nats.Msg{
		Subject: destinationId.ToSubject().String(),
		Reply:   replyTo,
		Header:  header,
		Data:    []byte(ev.Event),
	}

	if ev.ScheduleAt.After(time.Now()) {
		msg.Header.Add(string(glue.HeaderDelay), ev.ScheduleAt.Format(time.RFC3339))
	}

	ack, err := helpers.Js.PublishMsg(ctx, msg)
	if err != nil {
		helpers.ThrowPHPException(err.Error())
		return 0
	}
	return int64(ack.Sequence)
}
