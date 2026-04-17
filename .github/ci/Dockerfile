ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libvips-dev \
        libffi-dev \
        git \
        unzip \
        ca-certificates \
    && docker-php-ext-install ffi \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

RUN echo "ffi.enable=1" > /usr/local/etc/php/conf.d/ffi.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
