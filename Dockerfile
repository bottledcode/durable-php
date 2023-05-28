FROM php:8-zts AS base

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/bin/

RUN install-php-extensions igbinary parallel redis opcache @composer

COPY composer.json composer.lock /app/

WORKDIR /app

RUN composer install --no-dev --no-interaction --optimize-autoloader

COPY . /app

RUN groupadd -g 1000 app && \
    useradd -d /app -s /bin/bash -g 1000 -u 1000 app && \
    chown -R app:app /app && \
    adduser app sudo

ENTRYPOINT [ "php", "/src/Run.php" ]

FROM base as prod

USER app
