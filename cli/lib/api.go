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
	"strings"
)

func Startup(js jetstream.JetStream, logger *zap.Logger, port string, streamName string) {
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

	// GET /activity/{name}/{id}
	r.HandleFunc("/activity/{name}/{id}", func(writer http.ResponseWriter, request *http.Request) {
		if request.Method != "GET" {
			http.Error(writer, "Method Not Allowed", http.StatusMethodNotAllowed)
			return
		}

		vars := mux.Vars(request)
		id := vars["id"]
		name := vars["name"]

		store, err := getObjectStore("activities", js, context.Background())
		if err != nil {
			panic(err)
		}
		outputStatus(writer, store, name, id, logger)
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
		store, err := getObjectStore("entities", js, context.Background())
		if err != nil {
			panic(err)
		}

		vars := mux.Vars(request)
		id := vars["id"]
		name := vars["name"]

		jsonStr, err := store.GetString(context.Background(), GetRealIdFromHumanId(name, id))
		if err != nil {
			http.Error(writer, "Not Found", http.StatusNotFound)
			return
		}

		stateJson, err := base64.StdEncoding.DecodeString(jsonStr)
		if err != nil {
			http.Error(writer, "Internal Server Error", http.StatusInternalServerError)
			return
		}

		libraryDir, err := getLibraryDir("EntityDecoder.php")
		if err != nil {
			panic(err)
		}

		ue, _ := url.ParseRequestURI(libraryDir)
		headers := make(http.Header)

		req := &http.Request{
			Method: "PUT",
			URL:    ue,
			Proto:  "http",
			Body:   io.NopCloser(strings.NewReader(string(stateJson))),
			Header: headers,
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

		writer.WriteHeader(http.StatusOK)
		_, err = writer.Write([]byte(eventReader.data))
		if err != nil {
			return
		}

		http.Error(writer, "Not found", http.StatusNotFound)
	})

	// PUT /entity/{name}/{id}/{method}
	r.HandleFunc("/entity/{name}/{id}/{method}", func(writer http.ResponseWriter, request *http.Request) {
		if request.Method != "PUT" {
			http.Error(writer, "Method Not Allowed", http.StatusMethodNotAllowed)
			return
		}

		vars := mux.Vars(request)
		name := vars["name"]
		id := vars["id"]
		method := vars["method"]

		libraryDir, err := getLibraryDir("SendSignalEvent.php")
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
		headers.Add("Method", method)

		req := &http.Request{
			Method:           "PUT",
			URL:              ue,
			Proto:            "http",
			ProtoMajor:       0,
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
		id := vars["id"]
		name := vars["name"]

		store, err := getObjectStore("orchestrations", js, context.Background())
		if err != nil {
			panic(err)
		}
		outputStatus(writer, store, name, id, logger)
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

func getStatus(writer http.ResponseWriter, store jetstream.ObjectStore, name string, id string) (interface{}, error) {
	jsonStr, err := store.GetString(context.Background(), GetRealIdFromHumanId(name, id))
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

func outputStatus(writer http.ResponseWriter, store jetstream.ObjectStore, name string, id string, logger *zap.Logger) {
	status, _ := getStatus(writer, store, name, id)
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
	names := make([]string, 0)
	for _, activity := range activities {
		if strings.HasPrefix(activity.Name, "/") {
			continue
		}

		name, id := GetRealNameFromEncodedName(activity.Name)
		names = append(names, name+":"+id)
	}

	writer.Header().Add("Content-Type", "application/json")
	if err := json.NewEncoder(writer).Encode(names); err != nil {
		panic(err)
	}
}
