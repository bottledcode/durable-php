FROM php:8.3-zts

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions parallel pcntl uuid @composer

COPY . /app
WORKDIR /app
RUN composer install
