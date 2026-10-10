# Native tools shared by development and the isolated media runtime.
FROM php:8.5-cli-alpine AS media-base

RUN apk add --no-cache \
    bash \
    'ffmpeg>=8.1.2-r0' \
    imagemagick \
    libheif-tools \
    perl \
    php85-gd \
    php85-pecl-imagick \
    exiftool && \
    echo "extension=/usr/lib/php85/modules/imagick.so" > /usr/local/etc/php/conf.d/imagick.ini && \
    echo "extension=/usr/lib/php85/modules/gd.so" > /usr/local/etc/php/conf.d/gd.ini

# Development/installation retains its credential access and tooling.
FROM media-base AS dev

RUN apk add --no-cache git nodejs npm openssh-client php85-pecl-pcov && \
    echo "extension=/usr/lib/php85/modules/pcov.so" > /usr/local/etc/php/conf.d/pcov.ini && \
    echo "pcov.enabled=0" >> /usr/local/etc/php/conf.d/pcov.ini && \
    git config --global --add safe.directory /app

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/.composer \
    NPM_CONFIG_CACHE=/tmp/.npm \
    PATH="${PATH}:/app/.build/bin"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Resolve production dependencies during image construction, never during a media run.
FROM dev AS runtime-vendor
COPY composer.json version /app/
COPY src /app/src/
COPY config /app/config/
RUN composer install --no-dev --no-scripts --classmap-authoritative --no-interaction --no-progress && \
    php src/Dependencies.php

# Immutable code/vendor/DI artifact; only media, private JSON cache and tmp are writable.
FROM media-base AS runtime
WORKDIR /app
COPY --from=runtime-vendor /app/src /app/src/
COPY --from=runtime-vendor /app/config /app/config/
COPY --from=runtime-vendor /app/version /app/version
COPY --from=runtime-vendor /app/.build/vendor /app/.build/vendor/
COPY --from=runtime-vendor /app/.build/cache/DependencyContainer.php /app/.build/cache/DependencyContainer.php
COPY scripts/clear-cache.php /app/scripts/clear-cache.php
COPY scripts/runtime-resources-smoke.php /app/scripts/runtime-resources-smoke.php
COPY scripts/runtime-image-smoke.sh /app/scripts/runtime-image-smoke.sh
COPY config/imagemagick-policy.xml /etc/ImageMagick-7/policy.xml
RUN mkdir /cache && chmod 1777 /cache
ENV CACHE_DIR=/cache
USER 1000:1000
ENTRYPOINT ["php", "/app/src/Renamer.php"]
CMD ["--help"]

# SPC builder stage
FROM ubuntu:24.04 AS builder

ARG USERID=1000
ARG GROUPID=1000

RUN apt-get update && \
    apt-get install -y --no-install-recommends \
    autoconf \
    automake \
    autopoint \
    bison \
    build-essential \
    bzip2 \
    ca-certificates \
    cmake \
    curl \
    flex \
    git \
    libtool \
    openssl \
    patchelf \
    re2c \
    sudo \
    unzip \
    zip && \
    rm -rf /var/lib/apt/lists/*

RUN (groupadd --gid ${GROUPID} renamer 2>/dev/null || groupmod -n renamer $(getent group ${GROUPID} | cut -d: -f1)) && \
    useradd --uid ${USERID} --gid ${GROUPID} --create-home renamer && \
    echo "renamer ALL=(ALL) NOPASSWD: ALL" >> /etc/sudoers
