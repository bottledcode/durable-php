FROM php:8-zts AS base

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/bin/

RUN install-php-extensions ev apcu parallel @composer && \
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

FROM base as prod

#USER app
