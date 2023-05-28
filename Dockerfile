FROM php:8-zts

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/bin/

RUN install-php-extensions igbinary parallel redis opcache @composer

USER 1000:1000

COPY composer.json composer.lock /app/

WORKDIR /app

RUN composer install --no-dev --no-interaction --no-progress --no-suggest --optimize-autoloader --no-scripts --no-plugins

COPY . /app

ENTRYPOINT [ "php", "/src/Run.php" ]
