FROM php:8.2-zts

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions parallel redis

COPY . /app
