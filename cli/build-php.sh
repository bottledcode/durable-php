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

os="$(uname -s | tr '[:upper:]' '[:lower:]')"

export CFLAGS="$CFLAGS -O3 -march=native -pipe" CXXFLAGS="$CXXFLAGS -O3 -march=native -pipe"

if [ -z "${PHP_EXTENSIONS}" ]; then
    #export PHP_EXTENSIONS="apcu,bcmath,bz2,calendar,ctype,curl,dom,exif,fileinfo,filter,gd,gmp,iconv,igbinary,intl,mbregex,mbstring,mysqli,mysqlnd,opcache,openssl,pcntl,pdo,phar,posix,readline,simplexml,soap,sockets,sodium,sysvmsg,sysvsem,tokenizer,uuid,uv,xml,xmlreader,xmlwriter,xsl,yaml,zip,zlib"
    export PHP_EXTENSIONS="apcu,bz2,ctype,curl,dom,filter,igbinary,intl,mbstring,opcache,openssl,pcntl,phar,posix,readline,sockets,sodium,tokenizer,uuid,uv,zip,zlib"
fi

if [ -z "${PHP_EXTENSION_LIBS}" ]; then
    export PHP_EXTENSION_LIBS=""
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

if [ -n "${CLEAN}" ]; then
    rm -Rf dist/
    go clean -cache
fi

# Build libphp if necessary
if [ -f "dist/static-php-cli/buildroot/lib/libphp.a" ]; then
    cd dist/static-php-cli
else
    mkdir -p dist/
    cd dist/

    if [ -d "static-php-cli/" ]; then
        cd static-php-cli/
        git pull
    else
        git clone --depth 1 --branch main https://github.com/withinboredom/static-php-cli
        cd static-php-cli/
    fi

    if type "brew" > /dev/null; then
        if ! type "composer" > /dev/null; then
            packages="composer"
        fi
        if ! type "go" > /dev/null; then
            packages="${packages} go"
        fi
        if [ -n "${RELEASE}" ] && ! type "gh" > /dev/null; then
            packages="${packages} gh"
        fi

        if [ -n "${packages}" ]; then
            # shellcheck disable=SC2086
            brew install --formula --quiet ${packages}
        fi
    fi

    composer install --no-dev -a

    if [ "${os}" = "linux" ]; then
        extraOpts="--disable-opcache-jit -I "memory_limit=2G" -I "opcache.enable_cli=1" -I "opcache.enable=1""
        echo ""
    fi

    if [ -n "${DEBUG_SYMBOLS}" ]; then
        extraOpts="${extraOpts} --no-strip"
    fi

    ./bin/spc doctor
    ./bin/spc fetch --with-php="${PHP_VERSION}" --for-extensions="${PHP_EXTENSIONS}"
    # the Brotli library must always be built as it is required by http://github.com/dunglas/caddy-cbrotli
    # shellcheck disable=SC2086

    if [ -z $BUILD ]; then
      ./bin/spc build --enable-zts --build-embed ${extraOpts} "${PHP_EXTENSIONS}" --with-libs="brotli,${PHP_EXTENSION_LIBS}"
    fi
fi
