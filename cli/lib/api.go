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
	"net/http"
	"net/url"
	"strings"
)

func Startup(js jetstream.JetStream, logger *zap.Logger, port string, streamName string) {
	r := mux.NewRouter()

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

	r.HandleFunc("/activity/{id}", func(writer http.ResponseWriter, request *http.Request) {
		if request.Method != "GET" {
			http.Error(writer, "Method Not Allowed", http.StatusMethodNotAllowed)
			return
		}

		vars := mux.Vars(request)
		id := vars["id"]

		store, err := getObjectStore("activities", js, context.Background())
		if err != nil {
			panic(err)
		}
		outputStatus(writer, store, id, logger)
	})

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

	// todo: this needs work
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

		// todo: hand off to php

		outputStatus(writer, store, id, logger)
	})

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

		ue, _ := url.ParseRequestURI(libraryDir)
		headers := make(http.Header)
		headers.Add("Name", name)
		headers.Add("Id", id)
		headers.Add("Method", method)

		req := &http.Request{
			Method: "PUT",
			URL:    ue,
			Proto:  "http",
			Body:   request.Body,
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
				Proto:            "http",
				ProtoMajor:       1,
				ProtoMinor:       1,
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

	logger.Fatal("server error", zap.Error(http.ListenAndServe(":"+port, r)))
}

func getStatus(writer http.ResponseWriter, store jetstream.ObjectStore, id string) (interface{}, error) {
	jsonStr, err := store.GetString(context.Background(), GetRealIdFromHumanId(id))
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

func outputStatus(writer http.ResponseWriter, store jetstream.ObjectStore, id string, logger *zap.Logger) {
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
	names := make([]string, 0)
	for _, activity := range activities {
		if strings.HasPrefix(activity.Name, "/") {
			continue
		}

		name := GetRealNameFromEncodedName(activity.Name)
		names = append(names, name)
	}

	writer.Header().Add("Content-Type", "application/json")
	if err := json.NewEncoder(writer).Encode(names); err != nil {
		panic(err)
	}
}
