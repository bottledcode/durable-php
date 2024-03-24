package lib

import (
	"context"
	"durable_php/auth"
	"durable_php/config"
	"durable_php/glue"
	"encoding/json"
	"fmt"
	"github.com/dunglas/frankenphp"
	"github.com/google/uuid"
	"github.com/gorilla/mux"
	"github.com/nats-io/nats.go"
	"github.com/nats-io/nats.go/jetstream"
	"github.com/typesense/typesense-go/typesense"
	"github.com/typesense/typesense-go/typesense/api"
	"github.com/typesense/typesense-go/typesense/api/pointer"
	"go.uber.org/zap"
	"io"
	"math/rand"
	"net/http"
	"os"
	"slices"
	"strconv"
	"strings"
	"time"
)

func generateCorrelationId() string {
	bytes := make([]byte, 16)
	for i := range bytes {
		bytes[i] = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"[rand.Intn(62)]
	}

	return string(bytes)
}

func getCorrelationId(ctx context.Context, hHeaders *http.Header, nHeaders *nats.Header) context.Context {
	if ctx.Value("cid") != nil {
		return ctx
	}

	if nHeaders != nil && nHeaders.Get("Correlation-Id") != "" {
		return context.WithValue(ctx, "cid", nHeaders.Get("Correlation-Id"))
	}

	if hHeaders != nil && hHeaders.Get("X-Correlation-ID") != "" {
		return context.WithValue(ctx, "cid", hHeaders.Get("X-Correlation-ID"))
	}

	return context.WithValue(ctx, "cid", generateCorrelationId())
}

func logRequest(logger *zap.Logger, r *http.Request, ctx context.Context) {
	template, err := mux.CurrentRoute(r).GetPathTemplate()
	if err != nil {
		return
	}
	logger.Info(template, zap.Any("cid", ctx.Value("cid")), zap.Any("vars", mux.Vars(r)))
}

func Startup(ctx context.Context, js jetstream.JetStream, logger *zap.Logger, port string, config *config.Config) {
	r := mux.NewRouter()

	rm := auth.GetResourceManager(ctx, js)

	// todo: allow specific origins from config
	handleCors := func(writer http.ResponseWriter, request *http.Request) bool {
		if request.Method == "OPTIONS" {
			writer.Header().Set("Access-Control-Allow-Origin", "*")
			writer.Header().Set("Access-Control-Allow-Methods", "POST, GET, OPTIONS, PUT, DELETE")
			writer.Header().Set("Access-Control-Allow-Headers", "Accept, Content-Type, Content-Length, Accept-Encoding, X-CSRF-Token, Authorization, X-Id")
			return true
		}

		writer.Header().Set("Access-Control-Allow-Origin", "*")
		return false
	}

	r.HandleFunc("/query", func(writer http.ResponseWriter, request *http.Request) {
		if stop := handleCors(writer, request); stop {
			return
		}

		if user, ok := auth.ExtractUser(request, config); !ok || user == nil {
			http.Error(writer, "Not Authorized", http.StatusForbidden)
			return
		}

		if path, ok := glue.GetLibraryDir("/../Gateway/Graph/index.php"); ok {
			request.URL.Path = path
			request.Proto = "DPHP/1.0"
		} else {
			http.Error(writer, "Missing vendor directory", http.StatusInternalServerError)
			return
		}
		request.Header.Add("DPHP_BOOTSTRAP", config.Bootstrap)

		ctx := getCorrelationId(ctx, &request.Header, nil)
		logRequest(logger, request, ctx)

		request, err := frankenphp.NewRequestWithContext(request, frankenphp.WithRequestEnv(map[string]string{
			"LOG_LEVEL": "DEBUG",
		}), frankenphp.WithRequestLogger(logger))
		if err != nil {
			logger.Error("Failed to serve request", zap.Error(err))
			http.Error(writer, "Internal Server Error", http.StatusInternalServerError)
			return
		}

		err = frankenphp.ServeHTTP(writer, request)
		if err != nil {
			logger.Error("Failed to serve request", zap.Error(err))
			http.Error(writer, "Internal Server Error", http.StatusInternalServerError)
			return
		}
	})

	// GET /activities
	// list all activities
	r.HandleFunc("/activities", func(writer http.ResponseWriter, request *http.Request) {
		if stop := handleCors(writer, request); stop {
			return
		}

		if request.Method != "GET" {
			http.Error(writer, "Method Not Allowed", http.StatusMethodNotAllowed)
			return
		}

		if user, ok := auth.ExtractUser(request, config); ok && user.IsAdmin() {
			http.Error(writer, "Not Authorized", http.StatusForbidden)
			return
		}

		ctx := getCorrelationId(ctx, &request.Header, nil)
		logRequest(logger, request, ctx)

		store, err := glue.GetObjectStore("activities", js, context.Background())
		if err != nil {
			panic(err)
		}
		OutputList(writer, store)
	})

	// GET /activity/{id}
	// get the activity status
	r.HandleFunc("/activity/{id}", func(writer http.ResponseWriter, request *http.Request) {
		if stop := handleCors(writer, request); stop {
			return
		}

		if request.Method != "GET" {
			http.Error(writer, "Method Not Allowed", http.StatusMethodNotAllowed)
			return
		}

		if user, ok := auth.ExtractUser(request, config); ok && user.IsAdmin() {
			http.Error(writer, "Not Authorized", http.StatusForbidden)
			return
		}

		ctx := getCorrelationId(ctx, &request.Header, nil)
		logRequest(logger, request, ctx)

		vars := mux.Vars(request)
		id := &glue.ActivityId{
			Id: vars["id"],
		}
		err := OutputStatus(ctx, writer, id.ToStateId(), js, logger)
		if err != nil {
			http.Error(writer, "Internal Server Error", http.StatusInternalServerError)
			logger.Error("Failed to output status", zap.Error(err))
		}
	})

	// POST /entities/filter
	// list all entities
	r.HandleFunc("/entities/filter/{page}", func(writer http.ResponseWriter, request *http.Request) {
		if stop := handleCors(writer, request); stop {
			return
		}

		if request.Method != "POST" {
			http.Error(writer, "Method Not Allowed", http.StatusMethodNotAllowed)
			return
		}

		if user, ok := auth.ExtractUser(request, config); ok && user.IsAdmin() {
			http.Error(writer, "Not Authorized", http.StatusForbidden)
			return
		}

		vars := mux.Vars(request)
		page, err := strconv.Atoi(vars["page"])
		if err != nil {
			http.Error(writer, "Page should be integer", http.StatusBadRequest)
		}

		ctx := getCorrelationId(ctx, &request.Header, nil)
		logRequest(logger, request, ctx)

		if len(config.Extensions.Search.Collections) == 0 {
			logger.Error("Performing a search with search extension disabled")
			http.Error(writer, "Search Extension Disabled", http.StatusBadRequest)
			return
		}

		if !slices.Contains(config.Extensions.Search.Collections, "entities") {
			logger.Error("Performing a search with search extension disabled")
			http.Error(writer, "Search Extension Disabled", http.StatusBadRequest)
			return
		}

		body, err := io.ReadAll(request.Body)
		if err != nil {
			logger.Error("Failed parsing body", zap.Error(err), zap.Stack("failed"))
			http.Error(writer, "Internal Server Error", http.StatusInternalServerError)
			return
		}

		var filter map[string]interface{}
		err = json.Unmarshal(body, &filter)
		if err != nil {
			logger.Error("Failed parsing body", zap.Error(err), zap.Stack("failed"))
			http.Error(writer, "Internal Server Error", http.StatusInternalServerError)
			return
		}

		params := &api.SearchCollectionParams{
			Q:       filter["nameFilter"].(string),
			FacetBy: pointer.String(filter["groupByName"].(string)),
			Page:    pointer.Int(page),
		}

		client := typesense.NewClient(typesense.WithServer(config.Extensions.Search.Url), typesense.WithAPIKey(config.Extensions.Search.Key))
		search, err := client.Collection("entities").Documents().Search(ctx, params)
		if err != nil {
			logger.Error("Failed searching", zap.Error(err), zap.Stack("failed"))
			http.Error(writer, "Internal Server Error", http.StatusInternalServerError)
			return
		}

		response, err := json.Marshal(search.GroupedHits)
		if err != nil {
			logger.Error("Failed marshalling response", zap.Error(err), zap.Stack("failed"))
			http.Error(writer, "Internal Server Error", http.StatusInternalServerError)
			return
		}

		writer.Header().Add("Content-Type", "application/json")

		_, _ = writer.Write(response)
	})

	bootstrap := ctx.Value("bootstrap").(string)

	processReq := func(ctx context.Context, writer http.ResponseWriter, request *http.Request, id *glue.StateId, function glue.Method, headers http.Header) {
		logger.Debug("Processing request to call function", zap.String("function", string(function)), zap.Any("Headers", headers))
		ctx, cancel := context.WithCancel(context.WithValue(ctx, "bootstrap", bootstrap))
		defer cancel()

		msgs, stateFile, err, responseHeaders := glue.GlueFromApiRequest(ctx, request, function, logger, js, id, headers)
		if err != nil {
			http.Error(writer, "Internal Server Error", http.StatusInternalServerError)
			logger.Error("Failed to glue", zap.Error(err))
			return
		}
		logger.Debug("Received result", zap.Int("count", len(msgs)), zap.Int("stateFile size", len(stateFile)))

		for _, msg := range msgs {
			msg.Subject = fmt.Sprintf("%s.%s", config.Stream, msg.Subject)
			msg.Header.Add("Correlation-Id", ctx.Value("cid").(string))
			_, err := js.PublishMsg(ctx, msg)
			if err != nil {
				http.Error(writer, "Internal Server Error", http.StatusInternalServerError)
				logger.Error("Failed to glue", zap.Error(err))
				return
			}
		}

		f, err := os.Open(stateFile)
		if err != nil {
			http.Error(writer, "Internal Server Error", http.StatusInternalServerError)
			logger.Error("Failed to glue", zap.Error(err))
			return
		}

		for name, values := range *responseHeaders {
			for _, value := range values {
				writer.Header().Add(name, value)
			}
		}

		_, err = io.Copy(writer, f)
		if err != nil {
			http.Error(writer, "Internal Server Error", http.StatusInternalServerError)
			logger.Error("Failed to glue", zap.Error(err))
			return
		}
	}

	// GET /entity/{name}/{id}
	// get an entity state and status
	// PUT /entity/{name}/{id}
	// signal an entity
	r.HandleFunc("/entity/{name}/{id}", func(writer http.ResponseWriter, request *http.Request) {
		if stop := handleCors(writer, request); stop {
			return
		}

		vars := mux.Vars(request)
		id := &glue.EntityId{
			Name: strings.TrimSpace(vars["name"]),
			Id:   strings.TrimSpace(vars["id"]),
		}

		ctx := getCorrelationId(ctx, &request.Header, nil)
		logRequest(logger, request, ctx)

		if request.Method == "GET" {
			logger.Debug("Getting entity status", zap.String("id", id.String()))
			headers := make(http.Header)

			ctx, cancel := context.WithCancel(ctx)
			defer cancel()

			ctx, done := authorize(writer, request, config, ctx, rm, id.ToStateId(), logger, true, auth.Output)
			if done {
				return
			}

			stateFile, _ := glue.GetStateFile(id.ToStateId(), js, ctx, logger)
			headers.Add("Entity-State", stateFile.Name())

			processReq(ctx, writer, request, id.ToStateId(), glue.DecodeEntity, headers)
			return
		}

		if request.Method == "PUT" {
			ctx, done := authorize(writer, request, config, ctx, rm, id.ToStateId(), logger, false, auth.Signal)
			if done {
				return
			}

			logger.Debug("Signal entity", zap.String("id", id.String()))
			processReq(ctx, writer, request, id.ToStateId(), glue.SignalEntity, make(http.Header))
			return
		}

		http.Error(writer, "Method Not Allowed", http.StatusMethodNotAllowed)
	})

	// GET /orchestrations
	// get list of orchestrations
	r.HandleFunc("/orchestrations", func(writer http.ResponseWriter, request *http.Request) {
		if stop := handleCors(writer, request); stop {
			return
		}

		if request.Method == "GET" {
			if user, ok := auth.ExtractUser(request, config); ok && user.IsAdmin() {
				http.Error(writer, "Not Authorized", http.StatusForbidden)
				return
			}

			store, err := glue.GetObjectStore("orchestrations", js, context.Background())
			if err != nil {
				panic(err)
			}
			OutputList(writer, store)
			return
		}

		ctx := getCorrelationId(ctx, &request.Header, nil)
		logRequest(logger, request, ctx)

		http.Error(writer, "Method Not Allowed", http.StatusMethodNotAllowed)
		return
	})

	// PUT /orchestration/{name}
	// start a new orchestration
	r.HandleFunc("/orchestration/{name}", func(writer http.ResponseWriter, request *http.Request) {
		if stop := handleCors(writer, request); stop {
			return
		}

		if request.Method != "PUT" {
			http.Error(writer, "Method Not Allowed", http.StatusMethodNotAllowed)
			return
		}

		ctx := getCorrelationId(ctx, &request.Header, nil)
		logRequest(logger, request, ctx)

		vars := mux.Vars(request)

		execId, err := uuid.NewV7()
		if err != nil {
			http.Error(writer, "Internal Server Error", http.StatusInternalServerError)
			logger.Error("Failed to create uuid", zap.Error(err))
			return
		}

		id := &glue.OrchestrationId{
			InstanceId:  vars["name"],
			ExecutionId: execId.String(),
		}

		ctx, done := authorize(writer, request, config, ctx, rm, id.ToStateId(), logger, false, auth.Signal)
		if done {
			return
		}

		processReq(ctx, writer, request, id.ToStateId(), glue.StartOrchestration, make(http.Header))
	})

	// PUT /orchestration/{name}/{id}
	// start a new orchestration
	// GET /orchestration/{name}/{id}?wait=??
	// get an orchestration status and optionally wait for it's completion
	r.HandleFunc("/orchestration/{name}/{id}", func(writer http.ResponseWriter, request *http.Request) {
		if stop := handleCors(writer, request); stop {
			return
		}

		vars := mux.Vars(request)

		ctx := getCorrelationId(ctx, &request.Header, nil)
		logRequest(logger, request, ctx)

		id := &glue.OrchestrationId{
			InstanceId:  strings.TrimSpace(vars["name"]),
			ExecutionId: strings.TrimSpace(vars["id"]),
		}

		if request.Method == "PUT" {
			ctx, done := authorize(writer, request, config, ctx, rm, id.ToStateId(), logger, false, auth.Signal)
			if done {
				return
			}

			processReq(ctx, writer, request, id.ToStateId(), glue.StartOrchestration, make(http.Header))
			return
		}

		if request.Method != "GET" {
			http.Error(writer, "Method Not Allowed", http.StatusMethodNotAllowed)
			return
		}

		ctx, done := authorize(writer, request, config, ctx, rm, id.ToStateId(), logger, true, auth.Output)
		if done {
			return
		}

		if !request.URL.Query().Has("wait") {
			err := OutputStatus(ctx, writer, id.ToStateId(), js, logger)
			if err != nil {
				http.Error(writer, "Internal Server Error", http.StatusInternalServerError)
				return
			}
			return
		}

		if request.URL.Query().Has("wait") {
			logger.Debug("Waiting for key change", zap.String("key", id.String()))
			timeout, err := time.ParseDuration(request.URL.Query().Get("wait") + "s")
			if err != nil {
				timeout = time.Minute
			}
			ctx, cancel := context.WithTimeout(context.Background(), timeout)
			defer cancel()

			bucket, err := js.CreateOrUpdateKeyValue(ctx, jetstream.KeyValueConfig{
				Bucket:      string(glue.Orchestration),
				Description: "Holds orchestration state and history",
				Compression: true,
			})
			if err != nil {
				panic(err)
			}

			watcher, err := bucket.Watch(ctx, id.ToStateId().ToSubject().String())
			if err != nil {
				panic(err)
				return
			}

			for update := range watcher.Updates() {
				if update == nil {
					logger.Debug("Skipping change")
					continue
				}

				logger.Debug("Got change!")
				status, err := extractStatus(update.Value())
				if err != nil {
					http.Error(writer, "Internal Server Error", http.StatusInternalServerError)
					return
				}
				if runtimeStatus, ok := status.(map[string]interface{})["runtimeStatus"].(string); ok {
					switch runtimeStatus {
					case "Completed":
						fallthrough
					case "Failed":
						fallthrough
					case "Canceled":
						fallthrough
					case "Terminated":
						logger.Debug("Got a completed status", zap.String("status", runtimeStatus))
						if err := writeStatus(writer, status); err != nil {
							return
						}
						return
					default:
						logger.Debug("Got an incomplete status", zap.String("status", runtimeStatus))
						continue
					}
				}
			}
		}
		http.Error(writer, "Invalid Request", http.StatusBadRequest)
	})

	// PUT /orchestration/{name}/{id}/{signal}
	r.HandleFunc("/orchestration/{name}/{id}/{signal}", func(writer http.ResponseWriter, request *http.Request) {
		if stop := handleCors(writer, request); stop {
			return
		}

		if request.Method != "PUT" {
			http.Error(writer, "Method Not Allowed", http.StatusMethodNotAllowed)
			return
		}

		ctx := getCorrelationId(ctx, &request.Header, nil)
		logRequest(logger, request, ctx)

		vars := mux.Vars(request)
		id := &glue.OrchestrationId{
			InstanceId:  vars["name"],
			ExecutionId: vars["id"],
		}
		method := vars["signal"]

		ctx, done := authorize(writer, request, config, ctx, rm, id.ToStateId(), logger, false, auth.Signal)
		if done {
			return
		}

		headers := make(http.Header)
		headers.Add("Signal", method)

		processReq(ctx, writer, request, id.ToStateId(), glue.SignalOrchestration, headers)
	})

	r.HandleFunc("/", func(writer http.ResponseWriter, request *http.Request) {
		logger.Warn("Unkown endpoint")
		ctx := getCorrelationId(ctx, &request.Header, nil)
		logRequest(logger, request, ctx)
	})

	logger.Fatal("server error", zap.Error(http.ListenAndServe(":"+port, r)))
}

func authorize(
	writer http.ResponseWriter,
	request *http.Request,
	config *config.Config,
	ctx context.Context,
	rm *auth.ResourceManager,
	id *glue.StateId,
	logger *zap.Logger,
	preventCreation bool,
	operation auth.Operation,
) (context.Context, bool) {
	if !config.Extensions.Authz.Enabled {
		return ctx, false
	}
	if user, ok := auth.ExtractUser(request, config); ok {
		ctx = auth.DecorateContextWithUser(ctx, user)
	}
	resource, err := rm.DiscoverResource(ctx, id, logger, preventCreation)
	if err != nil {
		logger.Warn("User attempted to create new resource not authorized to create", zap.Any("id", id.String()), zap.Error(err))
		http.Error(writer, "Not Authorized", http.StatusForbidden)
		return nil, true
	}
	if !resource.WantTo(operation, ctx) {
		logger.Warn("User attempted to access resource they are not allowed to access", zap.Any("id", id.String()), zap.Error(err))
		http.Error(writer, "Not Authorized", http.StatusForbidden)
		return nil, true
	}
	return ctx, false
}

func extractStatus(stateJson []byte) (interface{}, error) {
	var state map[string]interface{}
	if err := json.Unmarshal(stateJson, &state); err != nil {
		return nil, err
	}

	return state["status"], nil
}

func readStatus(stateFile *os.File) ([]byte, error) {
	jsonStr, err := os.ReadFile(stateFile.Name())
	if err != nil {
		return nil, err
	}

	return jsonStr, nil
}

func writeStatus(w http.ResponseWriter, status interface{}) error {
	w.Header().Add("Content-Type", "application/json")
	if err := json.NewEncoder(w).Encode(status); err != nil {
		http.Error(w, "Internal Server Error", http.StatusInternalServerError)
		return err
	}

	return nil
}

func OutputList(writer http.ResponseWriter, store jetstream.ObjectStore) {
	activities, err := store.List(context.Background())
	if err != nil {
		panic(err)
	}
	names := make([][]string, 0)
	for _, activity := range activities {
		if strings.HasPrefix(activity.Name, "/") {
			continue
		}

		id := glue.ParseStateId(activity.Headers.Get(string(glue.HeaderStateId)))
		t := id.String()
		parts := strings.Split(t, ":")[1:]
		names = append(names, parts)
	}

	writer.Header().Add("Content-Type", "application/json")
	if err := json.NewEncoder(writer).Encode(names); err != nil {
		panic(err)
	}
}

func OutputStatus(ctx context.Context, writer http.ResponseWriter, id *glue.StateId, stream jetstream.JetStream, logger *zap.Logger) error {
	ctx, cancel := context.WithCancel(ctx)
	defer cancel()
	stateFile, _ := glue.GetStateFile(id, stream, ctx, logger)
	defer stateFile.Close()

	state, err := readStatus(stateFile)
	if err != nil {
		return err
	}

	status, err := extractStatus(state)
	if err != nil {
		return err
	}

	err = writeStatus(writer, status)
	if err != nil {
		return err
	}

	return nil
}
