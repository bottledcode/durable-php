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
		filepath.Join("vendor", "bottledcode", "durable-php", "src", "Glue", target),
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
}

func glueFromApiRequest(ctx context.Context, r *http.Request, function string, logger *zap.Logger, stream jetstream.JetStream, id *StateId, headers http.Header) ([]*nats.Msg, string, error, *http.Header) {
	temp, err := os.CreateTemp("", "reqbody")
	if err != nil {
		return nil, "", err, nil
	}
	go func() {
		<-ctx.Done()
		logger.Debug("Deleting body", zap.String("file", temp.Name()))
		os.Remove(temp.Name())
	}()

	_, err = io.Copy(temp, r.Body)
	if err != nil {
		return nil, "", err, nil
	}
	temp.Close()

	glu := &glue{
		bootstrap: ctx.Value("bootstrap").(string),
		function:  function,
		input:     make([]any, 0),
		payload:   temp.Name(),
	}

	env := make(map[string]string)
	env["FROM_REQUEST"] = "1"
	env["STATE_ID"] = id.String()

	msgs, responseHeaders, _ := glu.execute(ctx, headers, logger, env, stream)

	return msgs, temp.Name(), nil, &responseHeaders
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

	jsonData, err := json.Marshal(g.input)
	if err != nil {
		logger.Warn("Unable to marshal input to json", zap.Any("input", g.input))
	}

	bodyBuf := bytes.NewBufferString(string(jsonData) + "\n\n")
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
		ContentLength:    0,
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
			id := ParseStateId(query[0])
			qid := query[1]
			wg.Add(1)
			go func() {
				defer wg.Done()
				stateFile, _ := getStateFile(id, stream, ctx, logger)

				mu.Lock()
				defer mu.Unlock()

				bodyBuf.WriteString(fmt.Sprintf("%s://%s\n", qid, stateFile.Name()))
				// todo: write state to file
			}()
		}
	}()

	logger.Debug("Executing event handler")
	err = frankenphp.ServeHTTP(writer, r)
	if err != nil {
		panic(err)
	}
	cancelCtx()
	wg.Wait()

	return writer.events, writer.Header(), writer.status
}

func getStateFile(id *StateId, stream jetstream.JetStream, ctx context.Context, logger *zap.Logger) (*os.File, func() error) {
	if id.kind == Orchestration {
		// orchestrations use optimistic concurrency and the kv store for state
		bucket, err := stream.CreateOrUpdateKeyValue(ctx, jetstream.KeyValueConfig{
			Bucket:      string(Orchestration),
			Description: "Holds orchestration state and history",
			Compression: true,
		})
		if err != nil {
			panic(err)
		}
		stateFile, err := os.CreateTemp("", "state")
		defer stateFile.Close()
		logger.Debug("Created stateFile", zap.String("filename", stateFile.Name()))

		toCreate := true

		get, err := bucket.Get(ctx, id.toSubject().String())
		if err == nil {
			toCreate = false
			_, err = io.Copy(stateFile, bytes.NewReader(get.Value()))
			if err != nil {
				panic(err)
			}
		}

		go func() {
			<-ctx.Done()
			logger.Debug("Deleting stateFile", zap.String("filename", stateFile.Name()))
			err := os.Remove(stateFile.Name())
			if err != nil {
				logger.Warn("Unable to delete stateFile", zap.String("name", stateFile.Name()))
			}
		}()

		return stateFile, func() error {
			fileData, err := os.ReadFile(stateFile.Name())
			if err != nil {
				return err
			}

			if toCreate {
				_, err := bucket.Create(ctx, id.toSubject().String(), fileData)
				if err != nil {
					return err
				}
			} else {
				_, err := bucket.Update(ctx, id.toSubject().String(), fileData, get.Revision())
				if err != nil {
					return err
				}
			}

			//logger.Info("State file updated", zap.String("name", stateFile.Name()), zap.String("Subject", id.toSubject().String()), zap.String("bucket", "orchestrations"), zap.String("key", get.Key()))
			logger.Debug("State file updated", zap.String("name", stateFile.Name()))
			// now we just need to watch the right key!

			return nil
		}
	}

	obj, err := GetObjectStore(id.kind, stream, ctx)
	if err != nil {
		panic(err)
	}
	stateFile, err := os.CreateTemp("", "state")
	if err != nil {
		panic(err)
	}
	defer stateFile.Close()
	logger.Debug("Created statefile", zap.String("filename", stateFile.Name()))

	err = obj.GetFile(ctx, id.toSubject().String(), stateFile.Name())
	if err != nil {
		// GetFile will delete the file, causing interesting things to happen, so we need to ensure it always exists
		os.Create(stateFile.Name())
	}

	go func() {
		<-ctx.Done()
		logger.Debug("Deleting statefile", zap.String("filename", stateFile.Name()))
		err := os.Remove(stateFile.Name())
		if err != nil {
			logger.Warn("Unable to delete stateFile", zap.String("name", stateFile.Name()))
		}
	}()

	return stateFile, func() error {
		info, err := obj.PutFile(ctx, stateFile.Name())
		if err != nil {
			return err
		}

		link, err := obj.AddLink(ctx, id.toSubject().String(), info)
		if err != nil {
			return err
		}
		if link.Headers.Get(string(HeaderStateId)) == "" {
			if link.Headers == nil {
				link.Headers = make(nats.Header)
			}

			link.Headers.Add(string(HeaderStateId), id.String())
			err = obj.UpdateMeta(ctx, id.toSubject().String(), link.ObjectMeta)
			if err != nil {
				return err
			}
		}

		logger.Debug("State file updated", zap.String("name", stateFile.Name()), zap.String("Subject", id.toSubject().String()), zap.String("bucket", info.Bucket), zap.String("key", link.Name))

		return nil
	}
}
