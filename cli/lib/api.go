package lib

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"github.com/gorilla/mux"
	"github.com/nats-io/nats.go"
	"github.com/nats-io/nats.go/jetstream"
	"go.uber.org/zap"
	"io"
	"math/rand"
	"net/http"
	"os"
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

func Startup(ctx context.Context, js jetstream.JetStream, logger *zap.Logger, port string, streamName string) {
	r := mux.NewRouter()

	// GET /activities
	// list all activities
	r.HandleFunc("/activities", func(writer http.ResponseWriter, request *http.Request) {
		if request.Method != "GET" {
			http.Error(writer, "Method Not Allowed", http.StatusMethodNotAllowed)
			return
		}

		ctx := getCorrelationId(ctx, &request.Header, nil)
		logRequest(logger, request, ctx)

		store, err := GetObjectStore("activities", js, context.Background())
		if err != nil {
			panic(err)
		}
		OutputList(writer, store)
	})

	// GET /activity/{id}
	// get the activity status
	r.HandleFunc("/activity/{id}", func(writer http.ResponseWriter, request *http.Request) {
		if request.Method != "GET" {
			http.Error(writer, "Method Not Allowed", http.StatusMethodNotAllowed)
			return
		}

		ctx := getCorrelationId(ctx, &request.Header, nil)
		logRequest(logger, request, ctx)

		vars := mux.Vars(request)
		id := &activityId{
			id: vars["id"],
		}

		store, err := GetObjectStore("activities", js, context.Background())
		if err != nil {
			panic(err)
		}
		OutputStatus(writer, store, id.toStateId(), logger)
	})

	// GET /entities
	// list all entities
	r.HandleFunc("/entities", func(writer http.ResponseWriter, request *http.Request) {
		if request.Method != "GET" {
			http.Error(writer, "Method Not Allowed", http.StatusMethodNotAllowed)
			return
		}

		ctx := getCorrelationId(ctx, &request.Header, nil)
		logRequest(logger, request, ctx)

		store, err := GetObjectStore("entities", js, context.Background())
		if err != nil {
			panic(err)
		}
		OutputList(writer, store)
	})

	bootstrap := ctx.Value("bootstrap").(string)

	processReq := func(ctx context.Context, writer http.ResponseWriter, request *http.Request, id *StateId, function string, headers http.Header) {
		logger.Debug("Processing request to call function", zap.String("function", function), zap.Any("Headers", headers))
		ctx, cancel := context.WithCancel(context.WithValue(ctx, "bootstrap", bootstrap))
		defer cancel()

		msgs, body, err := glueFromApiRequest(ctx, request, function, logger, js, id, headers)
		if err != nil {
			http.Error(writer, "Internal Server Error", http.StatusInternalServerError)
			logger.Error("Failed to glue", zap.Error(err))
			return
		}
		logger.Debug("Received result", zap.Int("count", len(msgs)), zap.Int("body size", len(body)))

		for _, msg := range msgs {
			msg.Subject = fmt.Sprintf("%s.%s", streamName, msg.Subject)
			msg.Header.Add("Correlation-Id", ctx.Value("cid").(string))
			_, err := js.PublishMsg(ctx, msg)
			if err != nil {
				http.Error(writer, "Internal Server Error", http.StatusInternalServerError)
				logger.Error("Failed to glue", zap.Error(err))
				return
			}
		}

		f, err := os.Open(body)
		if err != nil {
			http.Error(writer, "Internal Server Error", http.StatusInternalServerError)
			logger.Error("Failed to glue", zap.Error(err))
			return
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
		vars := mux.Vars(request)
		id := &entityId{
			name: strings.TrimSpace(vars["name"]),
			id:   strings.TrimSpace(vars["id"]),
		}

		ctx := getCorrelationId(ctx, &request.Header, nil)
		logRequest(logger, request, ctx)

		if request.Method == "GET" {
			logger.Debug("Getting entity status", zap.String("id", id.String()))
			processReq(ctx, writer, request, id.toStateId(), "entityDecoder", make(http.Header))
			return
		}

		if request.Method == "PUT" {
			logger.Debug("Signal entity", zap.String("id", id.String()))
			processReq(ctx, writer, request, id.toStateId(), "entitySignal", make(http.Header))
			return
		}

		http.Error(writer, "Method Not Allowed", http.StatusMethodNotAllowed)
	})

	// GET /orchestrations
	// get list of orchestrations
	r.HandleFunc("/orchestrations", func(writer http.ResponseWriter, request *http.Request) {
		if request.Method == "GET" {
			store, err := GetObjectStore("orchestrations", js, context.Background())
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
		if request.Method != "PUT" {
			http.Error(writer, "Method Not Allowed", http.StatusMethodNotAllowed)
			return
		}

		ctx := getCorrelationId(ctx, &request.Header, nil)
		logRequest(logger, request, ctx)

		vars := mux.Vars(request)

		id := &orchestrationId{
			instanceId:  vars["name"],
			executionId: "",
		}

		processReq(ctx, writer, request, id.toStateId(), "startOrchestration", make(http.Header))
	})

	// PUT /orchestration/{name}/{id}
	// start a new orchestration
	// GET /orchestration/{name}/{id}?wait=??
	// get an orchestration status and optionally wait for it's completion
	r.HandleFunc("/orchestration/{name}/{id}", func(writer http.ResponseWriter, request *http.Request) {
		vars := mux.Vars(request)

		ctx := getCorrelationId(ctx, &request.Header, nil)
		logRequest(logger, request, ctx)

		id := &orchestrationId{
			instanceId:  strings.TrimSpace(vars["name"]),
			executionId: strings.TrimSpace(vars["id"]),
		}

		if request.Method == "PUT" {
			processReq(ctx, writer, request, id.toStateId(), "startOrchestration", make(http.Header))
			return
		}

		if request.Method != "GET" {
			http.Error(writer, "Method Not Allowed", http.StatusMethodNotAllowed)
			return
		}

		store, err := GetObjectStore("orchestrations", js, context.Background())
		if err != nil {
			panic(err)
		}

		if !request.URL.Query().Has("wait") {
			OutputStatus(writer, store, id.toStateId(), logger)
			return
		}

		if request.URL.Query().Has("wait") {
			logger.Debug("Waiting for key change", zap.String("key", id.String()))
			ctx, cancel := context.WithTimeout(context.Background(), time.Minute*60)
			defer cancel()
			watch, err := store.Watch(ctx, jetstream.IncludeHistory())
			if err != nil {
				cancel()
				panic(err)
				return
			}

			for update := range watch.Updates() {
				logger.Debug("Got update", zap.Any("update", update))
				if update == nil {
					logger.Debug("Skipping change")
					continue
				}

				if update.Headers.Get(string(HeaderStateId)) == id.toStateId().String() {
					logger.Debug("Got change", zap.String("name", id.String()), zap.Any("update", update))
					status, err := GetStatus(writer, store, id.toStateId())
					if err != nil {
						http.Error(writer, "Internal Server Error", http.StatusInternalServerError)
						return
					}

					if runtimeStatus, ok := status.(map[string]interface{})["runtimeStatus"].(string); ok {
						switch runtimeStatus {
						case "Completed":
						case "Failed":
						case "Canceled":
						case "Terminated":
							marshal, err := json.Marshal(status)
							if err != nil {
								http.Error(writer, "Internal Server Error", http.StatusInternalServerError)
								return
							}
							_, err = writer.Write(marshal)
							if err != nil {
								http.Error(writer, "Internal Server Error", http.StatusInternalServerError)
								return
							}
							cancel()
							return
						default:
							continue
						}
					}
				}
			}
		}

		http.Error(writer, "Invalid Request", http.StatusBadRequest)
	})

	// PUT /orchestration/{name}/{id}/{signal}
	r.HandleFunc("/orchestration/{name}/{id}/{signal}", func(writer http.ResponseWriter, request *http.Request) {
		if request.Method != "PUT" {
			http.Error(writer, "Method Not Allowed", http.StatusMethodNotAllowed)
			return
		}

		ctx := getCorrelationId(ctx, &request.Header, nil)
		logRequest(logger, request, ctx)

		vars := mux.Vars(request)
		id := &orchestrationId{
			instanceId:  vars["name"],
			executionId: vars["id"],
		}
		method := vars["signal"]

		headers := make(http.Header)
		headers.Add("Signal", method)

		processReq(ctx, writer, request, id.toStateId(), "orchestrationSignal", headers)
	})

	logger.Fatal("server error", zap.Error(http.ListenAndServe(":"+port, r)))
}

func GetStatus(writer http.ResponseWriter, store jetstream.ObjectStore, id *StateId) (interface{}, error) {
	jsonStr, err := store.GetString(context.Background(), id.toSubject().String())
	if err != nil {
		http.Error(writer, "Not Found", http.StatusNotFound)
		return nil, err
	}

	stateJson, err := base64.StdEncoding.DecodeString(jsonStr)
	if err != nil {
		http.Error(writer, "Internal Server Error", http.StatusInternalServerError)
		return nil, err
	}

	var state map[string]interface{}
	if err := json.Unmarshal(stateJson, &state); err != nil {
		http.Error(writer, "Internal Server Error", http.StatusInternalServerError)
		return nil, err
	}

	return state["status"], nil
}

func OutputStatus(writer http.ResponseWriter, store jetstream.ObjectStore, id *StateId, logger *zap.Logger) {
	status, _ := GetStatus(writer, store, id)
	if status == nil {
		return
	}

	writer.Header().Add("Content-Type", "application/json")
	if err := json.NewEncoder(writer).Encode(status); err != nil {
		http.Error(writer, "Internal Server Error", http.StatusInternalServerError)
		logger.Error("Failed to encode json", zap.Error(err))
		return
	}
}

func OutputList(writer http.ResponseWriter, store jetstream.ObjectStore) {
	activities, err := store.List(context.Background())
	if err != nil {
		panic(err)
	}
	names := make([]*StateId, 0)
	for _, activity := range activities {
		if strings.HasPrefix(activity.Name, "/") {
			continue
		}

		id := ParseStateId(activity.Headers.Get(string(HeaderStateId)))
		names = append(names, id)
	}

	writer.Header().Add("Content-Type", "application/json")
	if err := json.NewEncoder(writer).Encode(names); err != nil {
		panic(err)
	}
}
