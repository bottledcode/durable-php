FROM php:8.3-zts

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions @composer ev sodium zip intl uuid ev pcntl parallel apcu

COPY . /app
WORKDIR /app
RUN composer install
