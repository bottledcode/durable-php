package helpers

import (
	"context"
	"errors"
	"os"
	"time"

	"github.com/bottledcode/durable-php/cli/auth"
	"github.com/bottledcode/durable-php/cli/config"
	"github.com/bottledcode/durable-php/cli/glue"
	"github.com/dunglas/frankenphp"
	"github.com/nats-io/nats-server/v2/server"
	"github.com/nats-io/nats.go/jetstream"
	"go.uber.org/zap"
	"go.uber.org/zap/zapcore"
)

import "C"

// HandleError handles errors conditionally based on execution context
// In PHP context: throws PHP exception
// Outside PHP context: logs error with ERROR level
func HandleError(err error) {
	// Comprehensive logging for authorization failures
	logger := GetLogger(zapcore.ErrorLevel)
	logger.Error("AUTHORIZATION FAILURE: Request blocked during worker initialization",
		zap.String("error", err.Error()),
		zap.String("phase", "pre-php-initialization"),
		zap.String("action", "logged-instead-of-exception"),
		zap.String("impact", "request-will-be-rejected"),
		zap.String("troubleshooting", "check user permissions and resource access rules"))
}

func LogError(msg string) {
	Logger.Error("Extension error", zap.String("error", msg))
}

func GetLogger(level zapcore.Level) *zap.Logger {
	atom := zap.NewAtomicLevel()
	atom.SetLevel(level)

	cfg := zap.NewDevelopmentEncoderConfig()
	core := zapcore.NewCore(zapcore.NewConsoleEncoder(cfg), os.Stderr, atom)
	return zap.New(core)
}

type Consumer struct {
	Context context.Context
	Done    context.CancelFunc
	Msg     jetstream.MessagesContext
}

var Logger *zap.Logger
var NatsState string
var Js jetstream.JetStream
var Config *config.Config
var NatServer *server.Server
var Ctx context.Context

func ParseStateId(arr *frankenphp.Array) (id string, err error) {
	_, idx := arr.At(0)
	ok := false
	if id, ok = idx.(string); ok {
		return
	}
	Logger.Warn("Failed to parse state id", zap.Any("id", idx))
	LogError("Failed to parse state id")
	return "", errors.New("Failed to parse state id")
}

func ParseEvent(arr *frankenphp.Array) (ev *glue.EventMessage, err error) {
	ev = &glue.EventMessage{}

	for i := uint32(0); i < arr.Len(); i++ {
		k, v := arr.At(i)
		if k.Type == frankenphp.PHPIntKey {
			LogError("Event cannot contain integer keys")
		}
		switch k.Str {
		case "eventId":
			ev.EventId = v.(string)
		case "event":
			ev.Event = v.(string)
		case "eventType":
			ev.EventType = v.(string)
		case "destination":
			ev.Destination = v.(string)
		case "meta":
			ev.Meta = v.(string)
		case "replyTo":
			ev.ReplyTo = v.(string)
		case "sourceOps":
			ev.SourceOps = v.(string)
		case "targetOps":
			ev.TargetOps = v.(string)
		case "targetType":
			ev.TargetType = v.(string)
		case "scheduleAt":
			if str, ok := v.(string); ok {
				ev.ScheduleAt, err = time.Parse(time.RFC3339, str)
			}
		default:
			LogError("Unknown event key: " + k.Str)
			return nil, errors.New("Unknown event key: " + k.Str)
		}
	}
	return
}

func GetUserContext(arr *frankenphp.Array) *auth.User {
	user := &auth.User{}

	for i := uint32(0); i < arr.Len(); i++ {
		k, v := arr.At(i)
		if k.Type == frankenphp.PHPStringKey {
			if k.Str == "userId" {
				user.UserId = auth.UserId(v.(string))
			}
			if k.Str == "roles" {
				for j := uint32(0); j < v.(*frankenphp.Array).Len(); j++ {
					_, n := v.(*frankenphp.Array).At(j)
					user.Roles = append(user.Roles, auth.Role(n.(string)))
				}
			}
		}
	}

	return user
}
