FROM php:8.4-cli-alpine

RUN apk add --no-cache \
    bash \
    git \
    openssh-client

RUN git config --global --add safe.directory /app

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app
