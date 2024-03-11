#!/bin/sh

#
# Copyright ©2024 Robert Landers
#
# Permission is hereby granted, free of charge, to any person obtaining a copy
# of this software and associated documentation files (the “Software”), to deal
#  in the Software without restriction, including without limitation the rights
#  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
#  copies of the Software, and to permit persons to whom the Software is
#  furnished to do so, subject to the following conditions:
#
# The above copyright notice and this permission notice shall be included in
# all copies or substantial portions of the Software.
#
# THE SOFTWARE IS PROVIDED “AS IS”, WITHOUT WARRANTY OF ANY KIND,
# EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
# MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
# IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY
# CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT
# OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE
# OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
#

set -o errexit

if ! type "git" > /dev/null; then
    echo "The \"git\" command must be installed."
    exit 1
fi

cd dist/static-php-cli

arch="$(uname -m)"
os="$(uname -s | tr '[:upper:]' '[:lower:]')"
md5binary="md5sum"
if [ "${os}" = "darwin" ]; then
    os="mac"
    md5binary="md5 -q"
fi

if [ -z "${PHP_EXTENSIONS}" ]; then
    export PHP_EXTENSIONS="apcu,bcmath,bz2,calendar,ctype,curl,dom,exif,fileinfo,filter,gmp,gd,iconv,igbinary,mbregex,mbstring,opcache,openssl,pcntl,phar,posix,readline,simplexml,sockets,sodium,sysvsem,tokenizer,uuid,uv,xml,xmlreader,xmlwriter,zip,zlib"
fi

if [ -z "${PHP_EXTENSION_LIBS}" ]; then
    export PHP_EXTENSION_LIBS="bzip2,freetype,libavif,libjpeg,libwebp,libzip"
fi

if [ -z "${PHP_VERSION}" ]; then
    export PHP_VERSION="8.3"
fi

if [ -z "${FRANKENPHP_VERSION}" ]; then
    FRANKENPHP_VERSION="dev"
    export FRANKENPHP_VERSION
elif [ -d ".git/" ]; then
    CURRENT_REF="$(git rev-parse --abbrev-ref HEAD)"
    export CURRENT_REF

    if echo "${FRANKENPHP_VERSION}" | grep -F -q "."; then
        # Tag

        # Trim "v" prefix if any
        FRANKENPHP_VERSION=${FRANKENPHP_VERSION#v}
        export FRANKENPHP_VERSION

        git checkout "v${FRANKENPHP_VERSION}"
    else
        git checkout "${FRANKENPHP_VERSION}"
    fi
fi

bin="dphp-${os}-${arch}"

if [ -n "${CLEAN}" ]; then
    rm -Rf dist/
    go clean -cache
fi

CGO_CFLAGS="-O3 -DFRANKENPHP_VERSION=${FRANKENPHP_VERSION} -I${PWD}/buildroot/include/ $(./buildroot/bin/php-config --includes | sed s\#-I/\#-I"${PWD}"/buildroot/\#g)"
if [ -n "${DEBUG_SYMBOLS}" ]; then
    CGO_CFLAGS="-g ${CGO_CFLAGS}"
fi
export CGO_CFLAGS

if [ "${os}" = "mac" ]; then
    export CGO_LDFLAGS="-framework CoreFoundation -framework SystemConfiguration"
fi

CGO_LDFLAGS="${CGO_LDFLAGS}  ${PWD}/buildroot/lib/libbrotlicommon.a ${PWD}/buildroot/lib/libbrotlienc.a ${PWD}/buildroot/lib/libbrotlidec.a $(./buildroot/bin/php-config --ldflags) $(./buildroot/bin/php-config --libs) -lstdc++ -lbrotlidec -lssl -lcrypto -lbrotlienc -lbrotlicommon"
export CGO_LDFLAGS

LIBPHP_VERSION="$(./buildroot/bin/php-config --version)"
export LIBPHP_VERSION

cd ../..

# Embed PHP app, if any
if [ -n "${EMBED}" ] && [ -d "${EMBED}" ]; then
    tar -cf app.tar -C "${EMBED}" .
    ${md5binary} app.tar > app_checksum.txt
fi

if [ "${os}" = "linux" ]; then
    extraExtldflags="-Wl,-z,stack-size=0x80000"
fi

if [ -z "${DEBUG_SYMBOLS}" ]; then
    extraLdflags="-w -s"
fi

env
go env
go get durable_php
go build -buildmode=pie -tags "cgo netgo nats osusergo static_build" -ldflags "-linkmode=external -extldflags '-static-pie ${extraExtldflags}' ${extraLdflags} -X 'github.com/caddyserver/caddy/v2.CustomVersion=FrankenPHP ${FRANKENPHP_VERSION} PHP ${LIBPHP_VERSION} go_durable_php'" -o "dist/${bin}" durable_php

if [ -d "${EMBED}" ]; then
    truncate -s 0 app.tar
    truncate -s 0 app_checksum.txt
fi

if [ -z "${NO_COMPRESS}" ]; then
  if type "upx" > /dev/null; then
      #upx --best "dist/${bin}"
      echo "would compress"
  fi
fi

"dist/${bin}" version
