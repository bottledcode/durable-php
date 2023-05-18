FROM php:8-zts

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/bin/

RUN install-php-extensions igbinary parallel redis @composer
