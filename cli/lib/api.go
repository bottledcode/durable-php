package lib

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"github.com/gorilla/mux"
	"github.com/nats-io/nats.go/jetstream"
	"go.uber.org/zap"
	"net/http"
	"strings"
)

func Startup(js jetstream.JetStream, logger *zap.Logger, port string) {
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
	r.HandleFunc("/entity/{id}", func(writer http.ResponseWriter, request *http.Request) {
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

	r.HandleFunc("/entity/{id}/{method}", func(writer http.ResponseWriter, request *http.Request) {
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
		method := vars["method"]

		// todo: hand off to php
	})

	r.HandleFunc("/orchestrations", func(writer http.ResponseWriter, request *http.Request) {
		if request.Method == "GET" {
			store, err := getObjectStore("orchestrations", js, context.Background())
			if err != nil {
				panic(err)
			}
			outputList(writer, store)
		}

		if request.Method == "PUT" {
			// todo: hand off to php
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
