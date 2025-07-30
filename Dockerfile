FROM golang:1.24.5-alpine AS golang-base
FROM php:8.4.10-zts AS php-base
FROM golang-base AS cli-base-alpine

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
		php84 \
		php84-common \
		php84-ctype \
		php84-curl \
		php84-dom \
		php84-mbstring \
		php84-openssl \
		php84-pcntl \
		php84-phar \
		php84-posix \
		php84-session \
		php84-sodium \
		php84-tokenizer \
		php84-xml \
		php84-xmlwriter \
		upx \
		wget \
		xz ; \
	ln -sf /usr/bin/php83 /usr/bin/php

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PHP_EXTENSIONS="apcu,bcmath,bz2,calendar,ctype,curl,dba,dom,exif,fileinfo,filter,ftp,gd,gmp,gettext,iconv,igbinary,imagick,intl,ldap,mbregex,mbstring,mysqli,mysqlnd,opcache,openssl,parallel,pcntl,pdo,pdo_mysql,pdo_pgsql,pdo_sqlite,pgsql,phar,posix,protobuf,readline,redis,session,shmop,simplexml,soap,sockets,sodium,sqlite3,ssh2,sysvmsg,sysvsem,sysvshm,tidy,tokenizer,xlswriter,xml,xmlreader,xmlwriter,zip,zlib,yaml,zstd"
ENV PHP_EXTENSION_LIBS="bzip2,freetype,libavif,libjpeg,libwebp,libzip"

WORKDIR /go/src/app
COPY .git /go/src/app/.git
COPY cli/build-php.sh .
RUN --mount=type=secret,id=github-token GITHUB_TOKEN=$(cat /run/secrets/github-token) BUILD=no ./build-php.sh
RUN --mount=type=secret,id=github-token GITHUB_TOKEN=$(cat /run/secrets/github-token) ./build-php.sh

#RUN mkdir -p cli && mv dist cli/

COPY cli/go.mod cli/go.sum ./cli/
RUN cd cli && go mod graph | awk '{if ($1 !~ "@") print $2}' | xargs go get

COPY .git/ ./.git/
COPY cli/ ./cli/
WORKDIR /go/src/app/cli
RUN --mount=type=secret,id=github-token GITHUB_TOKEN=$(cat /run/secrets/github-token) ./build.sh

FROM php-base AS common

WORKDIR /app

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions @composer apcu bcmath bz2 calendar ctype curl dom exif fileinfo filter gmp gd iconv igbinary mbstring opcache openssl pcntl phar posix readline simplexml sockets sodium sysvsem tokenizer uv xml xmlreader xmlwriter zip zlib

FROM common AS builder

COPY --from=golang-base /usr/local/go /usr/local/go
ENV PATH=/usr/local/go/bin:$PATH

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

ARG VERSION=dev
ENV CGO_LDFLAGS="-lssl -lcrypto -lreadline -largon2 -lcurl -lonig -lz $PHP_LDFLAGS" CGO_CFLAGS="-DFRANKENPHP_VERSION=$VERSION $PHP_CFLAGS" CGO_CPPFLAGS=$PHP_CPPFLAGS
ENV GOBIN=/usr/local/bin
RUN go get durable_php
#RUN go test ./...

RUN  CGO_CFLAGS=$(php-config --includes) CGO_LDFLAGS="$(php-config --ldflags) $(php-config --libs)" go install --tags nowatcher -ldflags "-w -s -X 'main.version=$VERSION'"

FROM common AS durable-php
COPY --from=builder /usr/local/bin/durable_php /usr/local/bin/dphp
ENTRYPOINT ["dphp"]

WORKDIR /app

FROM durable-php AS test
COPY . .
CMD ["--bootstrap=tests/PerformanceTests/bootstrap.php","--port=8080"]
