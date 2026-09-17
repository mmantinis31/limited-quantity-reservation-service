# syntax=docker/dockerfile:1.7

FROM composer:2.8 AS composer

FROM php:8.4.25-fpm-alpine3.24

RUN set -eux; \
    apk add --no-cache \
        git \
        icu-libs \
        libxml2 \
        oniguruma \
        unzip; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libxml2-dev \
        oniguruma-dev; \
    docker-php-ext-install -j"$(nproc)" \
        dom \
        intl \
        mbstring \
        opcache \
        pdo_mysql \
        simplexml \
        xml \
        xmlwriter; \
    apk del .build-deps

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY docker/php/conf.d/app.ini $PHP_INI_DIR/conf.d/99-app.ini

RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/html
