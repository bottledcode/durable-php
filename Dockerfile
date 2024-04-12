FROM golang:1.22-alpine AS cli-base-alpine

SHELL ["/bin/ash", "-eo", "pipefail", "-c"]

RUN apk update; \
	apk add --no-cache \
		autoconf \
		automake \
		bash \
		binutils \
        binutils-gold \
		bison \
		build-base \
		cmake \
		composer \
		curl \
		file \
		flex \
		g++ \
		gcc \
		git \
		jq \
		libgcc \
		libstdc++ \
		libtool \
		linux-headers \
		m4 \
		make \
		pkgconfig \
		php83 \
		php83-common \
		php83-ctype \
		php83-curl \
		php83-dom \
		php83-mbstring \
		php83-openssl \
		php83-pcntl \
		php83-phar \
		php83-posix \
		php83-session \
		php83-sodium \
		php83-tokenizer \
		php83-xml \
		php83-xmlwriter \
		upx \
		wget \
		xz ; \
	ln -sf /usr/bin/php83 /usr/bin/php

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PHP_EXTENSIONS="apcu,bcmath,bz2,calendar,ctype,curl,dom,exif,fileinfo,filter,gmp,gd,iconv,igbinary,mbregex,mbstring,opcache,openssl,pcntl,phar,posix,readline,simplexml,sockets,sodium,sysvsem,tokenizer,uv,xml,xmlreader,xmlwriter,zip,zlib"
ENV PHP_EXTENSION_LIBS="bzip2,freetype,libavif,libjpeg,libwebp,libzip"

WORKDIR /go/src/app
COPY cli/build-php.sh .
RUN BUILD=no ./build-php.sh
RUN ./build-php.sh

#RUN mkdir -p cli && mv dist cli/

COPY cli/go.mod cli/go.sum ./cli/
RUN cd cli && go mod graph | awk '{if ($1 !~ "@") print $2}' | xargs go get

COPY .git/ ./.git/
COPY cli/ ./cli/
WORKDIR /go/src/app/cli
RUN ./build.sh

FROM php:8-zts AS common

WORKDIR /app

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
ARG VERSION=dev

FROM common AS builder

COPY --from=golang:1.22 /usr/local/go /usr/local/go
ENV PATH /usr/local/go/bin:$PATH

RUN apt-get update && \
	apt-get -y --no-install-recommends install \
	libargon2-dev \
	libbrotli-dev \
	libcurl4-openssl-dev \
	libonig-dev \
	libreadline-dev \
	libsodium-dev \
	libsqlite3-dev \
	libssl-dev \
	libxml2-dev \
	zlib1g-dev \
	&& \
	apt-get clean

WORKDIR /go/src/app
COPY --link cli/go.mod cli/go.sum ./
RUN go mod graph | awk '{if ($1 !~ "@") print $2}' | xargs go get

COPY --link cli/ .

ENV CGO_LDFLAGS="-lssl -lcrypto -lreadline -largon2 -lcurl -lonig -lz $PHP_LDFLAGS" CGO_CFLAGS="-DFRANKENPHP_VERSION=$VERSION $PHP_CFLAGS" CGO_CPPFLAGS=$PHP_CPPFLAGS
ENV GOBIN=/usr/local/bin
RUN go get durable_php
RUN go install -ldflags "-w -s -X 'main.version=$VERSION'"

FROM common AS durable-php
COPY --from=builder /usr/local/bin/durable_php /usr/local/bin/dphp

WORKDIR /app
