package lib

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"github.com/dunglas/frankenphp"
	"github.com/gorilla/mux"
	"github.com/nats-io/nats.go"
	"github.com/nats-io/nats.go/jetstream"
	"go.uber.org/zap"
	"io"
	"net/http"
	"net/url"
	"os"
	"strings"
	"time"
)

func Startup(ctx context.Context, js jetstream.JetStream, logger *zap.Logger, port string, streamName string) {
	r := mux.NewRouter()

	// GET /activities
	r.HandleFunc("/activities", func(writer http.ResponseWriter, request *http.Request) {
		if request.Method != "GET" {
			http.Error(writer, "Method Not Allowed", http.StatusMethodNotAllowed)
			return
		}

		store, err := getObjectStore("activities", js, context.Background())
		if err != nil {
			panic(err)
		}
		outputList(writer, store)
	})

	// GET /activity/{id}
	r.HandleFunc("/activity/{id}", func(writer http.ResponseWriter, request *http.Request) {
		if request.Method != "GET" {
			http.Error(writer, "Method Not Allowed", http.StatusMethodNotAllowed)
			return
		}

		vars := mux.Vars(request)
		id := &activityId{
			id: vars["id"],
		}

		store, err := getObjectStore("activities", js, context.Background())
		if err != nil {
			panic(err)
		}
		outputStatus(writer, store, id.toStateId(), logger)
	})

	// GET /entities
	r.HandleFunc("/entities", func(writer http.ResponseWriter, request *http.Request) {
		if request.Method != "GET" {
			http.Error(writer, "Method Not Allowed", http.StatusMethodNotAllowed)
			return
		}

		store, err := getObjectStore("entities", js, context.Background())
		if err != nil {
			panic(err)
		}
		outputList(writer, store)
	})

	// GET /entity/{name}/{id}
	r.HandleFunc("/entity/{name}/{id}", func(writer http.ResponseWriter, request *http.Request) {
		if request.Method != "GET" {
			http.Error(writer, "Method Not Allowed", http.StatusMethodNotAllowed)
			return
		}

		vars := mux.Vars(request)
		id := &entityId{
			name: vars["name"],
			id:   vars["id"],
		}

		ctx, cancel := context.WithCancel(ctx)
		defer cancel()

		stateFile := getStateFile(id.toStateId(), js, ctx, logger)
		defer stateFile.Close()

		glu := &glue{
			bootstrap: ctx.Value("bootstrap").(string),
			function:  "entityDecoder",
			input:     make([]any, 0),
			payload:   stateFile.Name(),
		}

		glu.execute(ctx, http.Header{}, logger, make(map[string]string), js)

		_, err := io.Copy(writer, stateFile)
		if err != nil {
			return
		}
	})

	// PUT /entity/{name}/{id}/{method}
	r.HandleFunc("/entity/{name}/{id}/{method}", func(writer http.ResponseWriter, request *http.Request) {
		if request.Method != "PUT" {
			http.Error(writer, "Method Not Allowed", http.StatusMethodNotAllowed)
			return
		}

		vars := mux.Vars(request)
		id := &entityId{name: vars["name"], id: vars["id"]}
		method := vars["method"]

		ctx, cancel := context.WithCancel(ctx)
		defer cancel()

		payload, err := os.CreateTemp("", "body")
		if err != nil {
			// bleh
			return
		}
		defer os.Remove(payload.Name())

		_, err = io.Copy(payload, request.Body)
		if err != nil {
			return
		}
		payload.Close()

		glu := &glue{
			bootstrap: ctx.Value("bootstrap").(string),
			function:  "sendSignal",
			input:     []any{id, method},
			payload:   payload.Name(),
		}

		msgs, _, _ := glu.execute(ctx, http.Header{}, logger, make(map[string]string), js)

		for _, msg := range msgs {
			_, err := js.PublishMsg(ctx, msg)
			if err != nil {
				//todo: display error
				return
			}
		}

		writer.WriteHeader(http.StatusNoContent)
	})

	// GET /orchestrations
	// PUT /orchestrations
	r.HandleFunc("/orchestrations", func(writer http.ResponseWriter, request *http.Request) {
		if request.Method == "GET" {
			store, err := getObjectStore("orchestrations", js, context.Background())
			if err != nil {
				panic(err)
			}
			outputList(writer, store)
			return
		}

		if request.Method == "PUT" {
			// todo: detect library directory
			libraryDir, err := getLibraryDir("StartOrchestrationEvent.php")
			if err != nil {
				panic(err)
			}

			ue, _ := url.ParseRequestURI(libraryDir)

			req := &http.Request{
				Method:           "PUT",
				URL:              ue,
				Proto:            "DPHP/1.0",
				ProtoMajor:       1,
				ProtoMinor:       0,
				Header:           nil,
				Body:             request.Body,
				GetBody:          nil,
				ContentLength:    0,
				TransferEncoding: nil,
				Close:            false,
				Host:             "",
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

			withContext, err := frankenphp.NewRequestWithContext(req)
			if err != nil {
				http.Error(writer, "Well shit", http.StatusInternalServerError)
				return
			}

			eventReader := &ConsumingResponseWriter{data: "", headers: make(http.Header)}

			err = frankenphp.ServeHTTP(eventReader, withContext)
			if err != nil {
				http.Error(writer, "Invalid body", http.StatusBadRequest)
				return
			}

			id := eventReader.headers.Get("Id")
			subject := streamName + "." + "orchestrations." + id

			logger.Info("Got event", zap.String("data", eventReader.data))

			msg := &nats.Msg{
				Subject: subject,
				Data:    []byte(eventReader.data),
			}

			_, err = js.PublishMsg(context.Background(), msg)
			if err != nil {
				http.Error(writer, "Failed to publish", http.StatusInternalServerError)
				return
			}

			writer.WriteHeader(http.StatusNoContent)
			return
		}

		http.Error(writer, "Method Not Allowed", http.StatusMethodNotAllowed)
		return
	})

	// GET /orchestration/{name}/{id}
	r.HandleFunc("/orchestration/{name}/{id}", func(writer http.ResponseWriter, request *http.Request) {
		if request.Method != "GET" {
			http.Error(writer, "Method Not Allowed", http.StatusMethodNotAllowed)
			return
		}

		vars := mux.Vars(request)
		id := orchestrationId{
			instanceId:  vars["name"],
			executionId: vars["id"],
		}

		store, err := getObjectStore("orchestrations", js, context.Background())
		if err != nil {
			panic(err)
		}

		if request.URL.Query().Has("wait") {
			logger.Debug("Waiting for key change", zap.String("key", id.String()))
			ctx, cancel := context.WithTimeout(context.Background(), time.Minute*60)
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

				if update.Name == realId {
					logger.Debug("Got change", zap.String("name", realId), zap.Any("update", update))
					jsonBytes := GetStateJson(store, context.Background(), realId)
					var obj map[string]interface{}
					if err := json.Unmarshal(jsonBytes, &obj); err != nil {
						http.Error(writer, "Internal Server Error", http.StatusInternalServerError)
						cancel()
						return
					}

					if status, ok := obj["status"].(map[string]interface{}); ok {
						if runtimeStatus, ok := status["runtimeStatus"].(string); ok {
							switch runtimeStatus {
							case "Completed":
							case "Failed":
							case "Canceled":
							case "Terminated":
								writer.Write(jsonBytes)
								cancel()
								return
							default:
								continue
							}
						}
					}
				}
			}
		}

		outputStatus(writer, store, id.toStateId(), logger)
	})

	// PUT /orchestration/{name}/{id}/{signal}
	r.HandleFunc("/orchestration/{name}/{id}/{signal}", func(writer http.ResponseWriter, request *http.Request) {
		if request.Method != "PUT" {
			http.Error(writer, "Method Not Allowed", http.StatusMethodNotAllowed)
			return
		}

		vars := mux.Vars(request)
		name := vars["name"]
		id := vars["id"]
		method := vars["signal"]

		libraryDir, err := getLibraryDir("RaiseEvent.php")
		if err != nil {
			panic(err)
		}

		ue, err := url.ParseRequestURI(libraryDir)
		if err != nil {
			http.Error(writer, "Script not found", http.StatusInternalServerError)
			logger.Error("Unable to construct url", zap.Error(err))
			return
		}
		logger.Info("Calling", zap.Any("url", ue))
		headers := make(http.Header)
		headers.Add("Name", name)
		headers.Add("Id", id)
		headers.Add("Event-Name", method)

		req := &http.Request{
			Method:           "PUT",
			URL:              ue,
			Proto:            "DPHP/1.0",
			ProtoMajor:       1,
			ProtoMinor:       0,
			Header:           headers,
			Body:             request.Body,
			GetBody:          nil,
			ContentLength:    0,
			TransferEncoding: nil,
			Close:            false,
			Host:             "",
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

		withContext, err := frankenphp.NewRequestWithContext(req)
		if err != nil {
			http.Error(writer, "Well shit", http.StatusInternalServerError)
			return
		}

		eventReader := &ConsumingResponseWriter{data: "", headers: make(http.Header)}

		err = frankenphp.ServeHTTP(eventReader, withContext)
		if err != nil {
			http.Error(writer, "Invalid body", http.StatusBadRequest)
			return
		}

		id = eventReader.headers.Get("Id")
		subject := streamName + ".entities." + id

		logger.Info("Got event", zap.String("data", eventReader.data))

		msg := &nats.Msg{
			Subject: subject,
			Data:    []byte(eventReader.data),
		}

		_, err = js.PublishMsg(context.Background(), msg)
		if err != nil {
			http.Error(writer, "Failed to publish", http.StatusInternalServerError)
			return
		}

		writer.WriteHeader(http.StatusNoContent)
	})

	logger.Fatal("server error", zap.Error(http.ListenAndServe(":"+port, r)))
}

func getStatus(writer http.ResponseWriter, store jetstream.ObjectStore, id *stateId) (interface{}, error) {
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

func outputStatus(writer http.ResponseWriter, store jetstream.ObjectStore, id *stateId, logger *zap.Logger) {
	status, _ := getStatus(writer, store, id)
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

func outputList(writer http.ResponseWriter, store jetstream.ObjectStore) {
	activities, err := store.List(context.Background())
	if err != nil {
		panic(err)
	}
	names := make([]*stateId, 0)
	for _, activity := range activities {
		if strings.HasPrefix(activity.Name, "/") {
			continue
		}

		id := parseStateId(activity.Headers.Get(string(HeaderStateId)))
		names = append(names, id)
	}

	writer.Header().Add("Content-Type", "application/json")
	if err := json.NewEncoder(writer).Encode(names); err != nil {
		panic(err)
	}
}
