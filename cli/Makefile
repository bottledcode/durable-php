DEBUG ?= 0

PHP_INCLUDES := $(shell php-config --includes)
PHP_LDFLAGS  := $(shell php-config --ldflags)
PHP_LIBS     := $(shell php-config --libs)

ifeq ($(DEBUG),1)
	XCADDY_FLAGS := -gcflags='all=-N -l' -tags=nobadger,nomysql,nopgx,nodphp,nobrotli
else
	XCADDY_FLAGS := -ldflags='-w -s' -tags=nobadger,nomysql,nopgx,nodphp,nobrotli
endif

LOCAL_MODULE := /home/withinboredom/code/durable-php/cli
GEN_STUB := /home/withinboredom/code/php-src/build/gen_stub.php
LOCAL_FRANKENPHP := /home/withinboredom/code/frankenphp

# Targets
frankenphp: ext/build/ext.go ext/build/ext_arginfo.h
	CGO_ENABLED=1 \
	XCADDY_GO_BUILD_FLAGS="$(XCADDY_FLAGS)" \
	CGO_CFLAGS="$(PHP_INCLUDES)" \
	CGO_LDFLAGS="$(PHP_LDFLAGS) $(PHP_LIBS)" \
	xcaddy build \
		--output frankenphp \
		--with github.com/dunglas/frankenphp=$(LOCAL_FRANKENPHP) \
		--with github.com/bottledcode/durable-php/cli=$(LOCAL_MODULE)

ext/build/ext_arginfo.h: ext/build/ext.stub.php
	$(GEN_STUB) ext/build/ext.stub.php ext/build