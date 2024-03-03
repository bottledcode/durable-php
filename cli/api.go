package main

import (
	"context"
	"fmt"
	"github.com/gorilla/mux"
	"github.com/nats-io/nats.go/jetstream"
	"go.uber.org/zap"
	"net/http"
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
		outputList(writer, err, store)
	})

	r.HandleFunc("/activity/{id}", func(writer http.ResponseWriter, request *http.Request) {
		vars := mux.Vars(request)
		id := vars["id"]

		fmt.Println(id)
	})

	logger.Fatal("server error", zap.Error(http.ListenAndServe(":"+port, r)))
}
