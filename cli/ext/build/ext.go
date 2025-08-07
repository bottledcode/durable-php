package build

/*
#include <stdlib.h>
#include "ext.h"
*/
import "C"
import "runtime/cgo"
import "unsafe"
import "github.com/dunglas/frankenphp"
import "context"
import "encoding/json"
import "errors"
import "os"
import "strings"
import "sync"
import "time"
import "github.com/bottledcode/durable-php/cli/appcontext"
import "github.com/bottledcode/durable-php/cli/auth"
import "github.com/bottledcode/durable-php/cli/config"
import "github.com/bottledcode/durable-php/cli/ext/helpers"
import "github.com/bottledcode/durable-php/cli/glue"
import "github.com/bottledcode/durable-php/cli/ids"
import "github.com/bottledcode/durable-php/cli/lib"
import "github.com/nats-io/nats-server/v2/server"
import "github.com/nats-io/nats-server/v2/test"
import "github.com/nats-io/nats.go"
import "github.com/nats-io/nats.go/jetstream"
import "go.uber.org/zap"

func init() {
	frankenphp.RegisterExtension(unsafe.Pointer(&C.ext_module_entry))
}

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

//export go_init_module
func go_init_module() {
	cfg, err := config.GetProjectConfig()
	if err != nil {
		panic(err)
	}
	helpers.Config = cfg

	helpers.Logger = helpers.GetLogger(zap.DebugLevel)
	logger := helpers.Logger

	logger.Info("Starting Durable PHP")

	helpers.Ctx = context.WithValue(context.Background(), "bootstrap", cfg.Bootstrap)

	boostrapNats := cfg.Nat.Bootstrap
	if cfg.Nat.Internal {
		logger.Warn("Running in dev mode, all data will be deleted at the end of this")
		helpers.NatsState, err = os.MkdirTemp("", "nats-state-*")
		if err != nil {
			panic(err)
		}

		helpers.NatServer = test.RunServer(&server.Options{
			Host:           "localhost",
			Port:           4222,
			NoLog:          true,
			NoSigs:         true,
			JetStream:      true,
			MaxControlLine: 2048,
			StoreDir:       helpers.NatsState,
			HTTPPort:       8222,
		})
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

			_, err = entityConsumer.Consume(func(msg jetstream.Msg) {
				incrementInt("e", 1)
				msg.Ack()
			})
			if err != nil {
				panic(err)
			}
			//defer consume.Drain()

			orchestrationConsumer, err := billingStream.CreateOrUpdateConsumer(ctx, jetstream.ConsumerConfig{
				Durable:       "orchestrationAggregator",
				FilterSubject: "billing." + cfg.Stream + ".orchestrations.>",
			})
			if err != nil {
				panic(err)
			}

			_, err = orchestrationConsumer.Consume(func(msg jetstream.Msg) {
				incrementInt("o", 1)
				msg.Ack()
			})
			if err != nil {
				panic(err)
			}
			//defer consume.Drain()

			activityConsumer, err := billingStream.CreateOrUpdateConsumer(ctx, jetstream.ConsumerConfig{
				Durable:       "activityAggregator",
				FilterSubject: "billing." + cfg.Stream + ".activities.>",
			})
			if err != nil {
				panic(err)
			}

			_, err = activityConsumer.Consume(func(msg jetstream.Msg) {
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
			//defer consume.Drain()
		}

		err := lib.StartBillingProcessor(ctx, cfg, helpers.Js, logger)
		if err != nil {
			panic(err)
		}
	}
}

//export go_shutdown_module
func go_shutdown_module() {
	if helpers.NatServer != nil {
		helpers.NatServer.Shutdown()
	}
	// remove nats state directory
	os.RemoveAll(helpers.NatsState)
}

//export emit_event
func emit_event(userContext *C.zval, event *C.zval, fromStr *C.zend_string) int64 {

	userVal := frankenphp.GoArray(unsafe.Pointer(userContext))

	var user *auth.User
	ctx, cancel := context.WithCancel(helpers.Ctx)
	defer cancel()

	if userVal != nil {
		userArr := frankenphp.GoArray(unsafe.Pointer(userVal))
		user = helpers.GetUserContext(userArr)
		if user.UserId == "" || len(user.Roles) == 0 {
			helpers.ThrowPHPException("User context is missing userId or roles")
			return 0
		}
		ctx = auth.DecorateContextWithUser(ctx, user)
	}

	from := ids.ParseStateId(frankenphp.GoString(unsafe.Pointer(fromStr)))

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
		return 0
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

type Worker struct {
	kind          ids.IdKind
	started       bool
	consumer      *helpers.Consumer
	activeId      *ids.StateId
	state         *glue.StateArray
	pendingEvents []*frankenphp.Array
	authContext   []byte
	currentCtx    context.Context
	currentMsg    jetstream.Msg
}

//export registerGoObject
func registerGoObject(obj interface{}) C.uintptr_t {
	handle := cgo.NewHandle(obj)
	return C.uintptr_t(handle)
}

//export getGoObject
func getGoObject(handle C.uintptr_t) interface{} {
	h := cgo.Handle(handle)
	return h.Value()
}

//export removeGoObject
func removeGoObject(handle C.uintptr_t) {
	h := cgo.Handle(handle)
	h.Delete()
}

//export create_Worker_object
func create_Worker_object() C.uintptr_t {
	obj := &Worker{}
	return registerGoObject(obj)
}
func (w *Worker) startEventLoop(kindStr *C.zend_string) {
	kind := ids.IdKind(frankenphp.GoString(unsafe.Pointer(kindStr)))

	switch kind {
	case ids.Activity:
	case ids.Entity:
	case ids.Orchestration:
	default:
		helpers.ThrowPHPException("Invalid event kind")
		return
	}
	w.kind = kind

	if w.started {
		helpers.ThrowPHPException("Event loop already running")
		return
	}

	ctx, done := context.WithCancel(helpers.Ctx)

	c := &helpers.Consumer{
		Context: ctx,
		Done:    done,
	}

	stream, err := helpers.Js.Stream(ctx, helpers.Config.Stream)
	if err != nil {
		helpers.ThrowPHPException(err.Error())
		return
	}

	c.Msg = lib.StartConsumer(ctx, helpers.Config, stream, helpers.Logger, w.kind)
	w.consumer = c
}

func (w *Worker) drainEventLoop() {
	if !w.started {
		return
	}
	w.consumer.Msg.Drain()
	w.started = false
}

func (w *Worker) __destruct() {
	w.consumer.Msg.Stop()
	w.consumer.Done()
}

func (w *Worker) getNextEvent() unsafe.Pointer {
	c := w.consumer

	ctx := c.Context
	logger := helpers.Logger
	js := helpers.Js

	msg, err := c.Msg.Next()
	if err != nil {
		helpers.ThrowPHPException(err.Error())
		return frankenphp.PHPString("", false)
	}

	meta, _ := msg.Metadata()
	headers := msg.Headers()

	currentUser := &auth.User{}
	b := msg.Headers().Get(string(glue.HeaderProvenance))
	err = json.Unmarshal([]byte(b), currentUser)
	if err != nil {
		logger.Warn("Failed to unmarshal event provenance",
			zap.Any("Provenance", msg.Headers().Get(string(glue.HeaderProvenance))),
			zap.Error(err),
		)
		currentUser = nil
	} else {
		ctx = auth.DecorateContextWithUser(ctx, currentUser)
	}

	if headers.Get(string(glue.HeaderDelay)) != "" && meta.NumDelivered == 1 {
		logger.Debug("Delaying message", zap.String("delay", msg.Headers().Get("Delay")), zap.Any("Headers", meta))
		schedule, err := time.Parse(time.RFC3339, msg.Headers().Get("Delay"))
		if err != nil {
			helpers.ThrowPHPException(err.Error())
			return frankenphp.PHPString("", false)
		}

		delay := time.Until(schedule)
		if err := msg.NakWithDelay(delay); err != nil {
			helpers.ThrowPHPException(err.Error())
			return frankenphp.PHPString("", false)
		}

		return w.getNextEvent()
	}

	if strings.HasSuffix(msg.Subject(), ".delete") {
		id := ids.ParseStateId(msg.Headers().Get(string(glue.HeaderStateId)))
		// todo: remove glue!
		err := glue.DeleteState(ctx, js, logger, id)
		if err != nil {
			helpers.ThrowPHPException(err.Error())
			return frankenphp.PHPString("", false)
		}
		return w.getNextEvent()
	}

	w.currentCtx = lib.GetCorrelationId(ctx, nil, &headers)

	rm := auth.GetResourceManager(ctx, js)

	w.authContext, w.activeId, w.state, err = lib.ProcessMessage(ctx, logger, msg, rm, helpers.Config, js)
	if err != nil {
		helpers.ThrowPHPException(err.Error())
		return frankenphp.PHPString("", false)
	}

	w.currentMsg = msg

	return frankenphp.PHPString(string(msg.Data()), false)
}

func (w *Worker) queryState(idStr *C.zend_string) unsafe.Pointer {
	id := ids.ParseStateId(frankenphp.GoString(unsafe.Pointer(idStr)))
	state, err := glue.GetStateArray(id, helpers.Js, w.currentCtx, helpers.Logger)
	if err != nil {
		helpers.ThrowPHPException(err.Error())
		return nil
	}
	return frankenphp.PHPArray(state.Data.Array)
}

func (w *Worker) getUser() unsafe.Pointer {
	if provenance, ok := w.currentCtx.Value(appcontext.CurrentUserKey).(*auth.User); ok {
		ret := &glue.Array{}
		ret.SetString("user", string(provenance.UserId))
		roles := &frankenphp.Array{}
		for _, r := range provenance.Roles {
			roles.Append(string(r))
		}
		ret.SetString("roles", roles)

		return frankenphp.PHPArray(ret.Array)
	}

	return nil
}

func (w *Worker) getSource() unsafe.Pointer {
	sourceId := ids.ParseStateId(w.currentMsg.Headers().Get(string(glue.HeaderEmittedBy)))
	return frankenphp.PHPString(sourceId.String(), false)
}

func (w *Worker) getCurrentId() unsafe.Pointer {
	return frankenphp.PHPString(w.activeId.String(), false)
}

func (w *Worker) getCorrelationId() unsafe.Pointer {
	return frankenphp.PHPString(w.currentCtx.Value("cid").(string), false)
}

func (w *Worker) getState() unsafe.Pointer {
	return frankenphp.PHPArray(w.state.Data.Array)
}

func (w *Worker) updateState(state *C.zval) {
	arr := frankenphp.GoArray(unsafe.Pointer(state))
	w.state.Data.Array = arr
}

func (w *Worker) emitEvent(event *C.zval) {
	arr := frankenphp.GoArray(unsafe.Pointer(event))
	w.pendingEvents = append(w.pendingEvents, arr)
}

func (w *Worker) delete() {}

//export startEventLoop_wrapper
func startEventLoop_wrapper(handle C.uintptr_t, kind *C.zend_string) {
	obj := getGoObject(handle)
	if obj == nil {
		return
	}
	structObj := obj.(*Worker)
	structObj.startEventLoop(kind)
}

//export drainEventLoop_wrapper
func drainEventLoop_wrapper(handle C.uintptr_t) {
	obj := getGoObject(handle)
	if obj == nil {
		return
	}
	structObj := obj.(*Worker)
	structObj.drainEventLoop()
}

//export __destruct_wrapper
func __destruct_wrapper(handle C.uintptr_t) {
	obj := getGoObject(handle)
	if obj == nil {
		return
	}
	structObj := obj.(*Worker)
	structObj.__destruct()
}

//export getNextEvent_wrapper
func getNextEvent_wrapper(handle C.uintptr_t) unsafe.Pointer {
	obj := getGoObject(handle)
	if obj == nil {
		return nil
	}
	structObj := obj.(*Worker)
	return structObj.getNextEvent()
}

//export queryState_wrapper
func queryState_wrapper(handle C.uintptr_t, stateId *C.zend_string) unsafe.Pointer {
	obj := getGoObject(handle)
	if obj == nil {
		return nil
	}
	structObj := obj.(*Worker)
	return structObj.queryState(stateId)
}

//export getUser_wrapper
func getUser_wrapper(handle C.uintptr_t) unsafe.Pointer {
	obj := getGoObject(handle)
	if obj == nil {
		return nil
	}
	structObj := obj.(*Worker)
	return structObj.getUser()
}

//export getSource_wrapper
func getSource_wrapper(handle C.uintptr_t) unsafe.Pointer {
	obj := getGoObject(handle)
	if obj == nil {
		return nil
	}
	structObj := obj.(*Worker)
	return structObj.getSource()
}

//export getCurrentId_wrapper
func getCurrentId_wrapper(handle C.uintptr_t) unsafe.Pointer {
	obj := getGoObject(handle)
	if obj == nil {
		return nil
	}
	structObj := obj.(*Worker)
	return structObj.getCurrentId()
}

//export getCorrelationId_wrapper
func getCorrelationId_wrapper(handle C.uintptr_t) unsafe.Pointer {
	obj := getGoObject(handle)
	if obj == nil {
		return nil
	}
	structObj := obj.(*Worker)
	return structObj.getCorrelationId()
}

//export getState_wrapper
func getState_wrapper(handle C.uintptr_t) unsafe.Pointer {
	obj := getGoObject(handle)
	if obj == nil {
		return nil
	}
	structObj := obj.(*Worker)
	return structObj.getState()
}

//export updateState_wrapper
func updateState_wrapper(handle C.uintptr_t, state *C.zval) {
	obj := getGoObject(handle)
	if obj == nil {
		return
	}
	structObj := obj.(*Worker)
	structObj.updateState(state)
}

//export emitEvent_wrapper
func emitEvent_wrapper(handle C.uintptr_t, eventDescription *C.zval) {
	obj := getGoObject(handle)
	if obj == nil {
		return
	}
	structObj := obj.(*Worker)
	structObj.emitEvent(eventDescription)
}

//export delete_wrapper
func delete_wrapper(handle C.uintptr_t) {
	obj := getGoObject(handle)
	if obj == nil {
		return
	}
	structObj := obj.(*Worker)
	structObj.delete()
}
