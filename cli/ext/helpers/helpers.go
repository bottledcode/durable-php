package helpers

/*
#include <php.h>
#include <zend_exceptions.h>

static inline void throw_exception(const char* msg) {
    zend_throw_exception(zend_ce_exception, msg, 0);
}
*/
import "C"
import (
	"github.com/bottledcode/durable-php/cli/auth"
	"github.com/bottledcode/durable-php/cli/config"
	"github.com/bottledcode/durable-php/cli/glue"
	"github.com/dunglas/frankenphp"
	"github.com/nats-io/nats.go/jetstream"
	"go.uber.org/zap"
	"go.uber.org/zap/zapcore"
	"os"
	"time"
	"unsafe"
)

func ThrowPHPException(msg string) {
	cstr := C.CString(msg)
	defer C.free(unsafe.Pointer(cstr))
	C.throw_exception(cstr)
}

func GetLogger(level zapcore.Level) *zap.Logger {
	atom := zap.NewAtomicLevel()
	atom.SetLevel(level)

	cfg := zap.NewDevelopmentEncoderConfig()
	core := zapcore.NewCore(zapcore.NewConsoleEncoder(cfg), os.Stderr, atom)
	return zap.New(core)
}

var Logger *zap.Logger
var NatsState string
var Js jetstream.JetStream
var Config *config.Config

func ParseEvent(arr *frankenphp.Array) (ev *glue.EventMessage, err error) {
	ev = &glue.EventMessage{}

	for i := uint32(0); i < arr.Len(); i++ {
		k, v := arr.At(i)
		if k.Type == frankenphp.PHPIntKey {
			ThrowPHPException("Event cannot contain integer keys")
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
			ev.ScheduleAt, err = time.Parse(time.RFC3339, v.(string))
		default:
			ThrowPHPException("Unknown event key: " + k.Str)
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
