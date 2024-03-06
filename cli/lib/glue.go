package lib

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"github.com/dunglas/frankenphp"
	"github.com/nats-io/nats.go"
	"github.com/nats-io/nats.go/jetstream"
	"go.uber.org/zap"
	"io"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"sync"
)

// this is the go side of the glue protocol
//
// the essence is very simple:
// - all output is written to the logger
// - strings starting with EVENT~!~ are parsed as a json string and can be turned into a nats msg
// - strings starting with QUERY~!~ are parsed as state ids and the state will be sent in the body

func getLibraryDir(target string) (string, bool) {
	dirs := []string{
		filepath.Join("src", "Glue", target),
		filepath.Join("vendor", "bottledcode", "src", "Glue", target),
	}

	for _, dir := range dirs {
		if _, err := os.Stat(dir); err == nil {
			return "/" + dir, true
		}
	}

	return "", false
}

type glue struct {
	// bootstrap Glue will load bootstrap file before calling any user code
	bootstrap string
	// function The callable to call and process the input
	function string
	// input to send to the function
	input []any
	// payload file that the script can read if needed
	payload string
	// raw body to stream to php
	raw *io.ReadCloser
}

func (g *glue) execute(ctx context.Context, headers http.Header, logger *zap.Logger, env map[string]string, stream jetstream.JetStream) ([]*nats.Msg, http.Header, int) {
	var dir string
	var ok bool
	if dir, ok = getLibraryDir("glue.php"); !ok {
		panic("no vendor directory!")
	}
	u, _ := url.Parse(dir)

	if headers == nil {
		headers = make(http.Header)
	}
	headers.Add("DPHP_BOOTSTRAP", g.bootstrap)
	headers.Add("DPHP_FUNCTION", g.function)
	headers.Add("DPHP_PAYLOAD", g.payload)

	var bodyBuf *bytes.Buffer

	if g.raw != nil {
		//todo: make this more efficient
		data, err := io.ReadAll(*g.raw)
		if err != nil {
			panic(err)
		}
		bodyBuf = bytes.NewBuffer(data)
	} else {
		jsonData, err := json.Marshal(g.input)
		if err != nil {
			logger.Warn("Unable to marshal input to json", zap.Any("input", g.input))
		}

		bodyBuf = bytes.NewBufferString(string(jsonData) + "\n\n")
	}
	body := io.NopCloser(bodyBuf)

	r := &http.Request{
		Method:           "POST",
		URL:              u,
		Proto:            "DPHP/1.0",
		ProtoMajor:       1,
		ProtoMinor:       0,
		Header:           headers,
		Body:             body,
		GetBody:          nil,
		ContentLength:    int64(len(jsonData)),
		TransferEncoding: nil,
		Close:            false,
		Host:             "localhost",
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

	r, err = frankenphp.NewRequestWithContext(r, frankenphp.WithRequestLogger(logger), frankenphp.WithRequestEnv(env))
	if err != nil {
		panic(err)
	}

	writer := &InternalLoggingResponseWriter{
		logger:  logger,
		isError: false,
		status:  0,
		events:  make([]*nats.Msg, 0),
		query:   make(chan []string),
	}

	var wg sync.WaitGroup
	ctx, cancelCtx := context.WithCancel(ctx)
	defer close(writer.query)

	go func() {
		mu := sync.RWMutex{}
		for query := range writer.query {
			id := parseStateId(query[0])
			qid := query[1]
			wg.Add(1)
			go func() {
				defer wg.Done()
				stateFile := getStateFile(id, stream, ctx, logger)

				mu.Lock()
				defer mu.Unlock()

				bodyBuf.WriteString(fmt.Sprintf("%s://%s", qid, stateFile.Name()))
			}()
		}
	}()

	err = frankenphp.ServeHTTP(writer, r)
	if err != nil {
		panic(err)
	}
	cancelCtx()
	wg.Wait()

	return writer.events, writer.Header(), writer.status
}

func getStateFile(id *stateId, stream jetstream.JetStream, ctx context.Context, logger *zap.Logger) *os.File {
	obj, err := getObjectStore(id.kind, stream, ctx)
	if err != nil {
		panic(err)
	}
	stateFile, err := os.CreateTemp("", "state")
	if err != nil {
		panic(err)
	}
	defer stateFile.Close()

	err = obj.GetFile(ctx, id.toSubject().String(), stateFile.Name())
	if err != nil {
		panic(err)
	}

	go func() {
		<-ctx.Done()
		err := os.Remove(stateFile.Name())
		if err != nil {
			logger.Warn("Unable to delete stateFile", zap.String("name", stateFile.Name()))
		}
	}()

	return stateFile
}

func updateStateFile(id *stateId, stateFile *os.File, stream jetstream.JetStream, ctx context.Context, logger *zap.Logger) {
	obj, err := getObjectStore(id.kind, stream, ctx)
	if err != nil {
		panic(err)
	}

	info, err := obj.PutFile(ctx, stateFile.Name())
	if err != nil {
		panic(err)
	}

	link, err := obj.AddLink(ctx, id.toSubject().String(), info)
	if err != nil {
		panic(err)
	}
	if link.Headers.Get(string(HeaderStateId)) == "" {
		link.Headers.Add(string(HeaderStateId), id.String())
		err = obj.UpdateMeta(ctx, id.toSubject().String(), link.ObjectMeta)
		if err != nil {
			panic(err)
		}
	}

	logger.Info("State file updated", zap.String("name", stateFile.Name()), zap.String("subject", id.toSubject().String()), zap.String("bucket", info.Bucket), zap.String("key", link.Name))
}
