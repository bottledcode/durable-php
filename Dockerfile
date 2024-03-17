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
ENV PHP_EXTENSIONS="apcu,bcmath,bz2,calendar,ctype,curl,dom,exif,fileinfo,filter,gmp,gd,iconv,igbinary,mbregex,mbstring,opcache,openssl,pcntl,phar,posix,readline,simplexml,sockets,sodium,sysvsem,tokenizer,uuid,uv,xml,xmlreader,xmlwriter,zip,zlib"
ENV PHP_EXTENSION_LIBS="bzip2,freetype,libavif,libjpeg,libwebp,libzip"

WORKDIR /go/src/app
COPY cli/build-php.sh .
RUN BUILD=no ./build-php.sh
RUN ./build-php.sh

COPY cli/go.mod cli/go.sum ./
RUN go mod graph | awk '{if ($1 !~ "@") print $2}' | xargs go get

COPY cli/build.sh .
COPY cli/lib ./lib
COPY cli/init ./init
COPY cli/auth ./auth
COPY cli/*.go .
RUN ./build.sh

FROM php:8-zts AS base

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/bin/

RUN install-php-extensions ev apcu pcntl parallel @composer && \
    mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" && \
    apt update && \
    apt install -y procps && \
    rm -rf /var/lib/apt/lists/*

COPY composer.json composer.lock /app/

WORKDIR /app

RUN composer install --no-interaction --optimize-autoloader

COPY . /app

#RUN groupadd -g 1000 app && \
#    useradd -d /app -s /bin/bash -g 1000 -u 1000 app && \
#    chown -R app:app /app && \
#    adduser app sudo

ENTRYPOINT [ "php", "-d", "opcache.enable_cli=1", "-d", "opcache.jit_buffer_size=50M", "-d", "opcache.jit=tracing", "src/Run.php" ]

FROM base as dev

RUN install-php-extensions xdebug
