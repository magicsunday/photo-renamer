# Development stage
FROM php:8.5-cli-alpine AS dev

RUN apk add --no-cache \
    bash \
    ffmpeg \
    git \
    imagemagick \
    libheif-tools \
    nodejs \
    npm \
    openssh-client \
    perl \
    php85-gd \
    php85-pecl-imagick \
    php85-pecl-pcov \
    exiftool && \
    echo "extension=/usr/lib/php85/modules/pcov.so" > /usr/local/etc/php/conf.d/pcov.ini && \
    echo "pcov.enabled=0" >> /usr/local/etc/php/conf.d/pcov.ini && \
    echo "extension=/usr/lib/php85/modules/imagick.so" > /usr/local/etc/php/conf.d/imagick.ini && \
    echo "extension=/usr/lib/php85/modules/gd.so" > /usr/local/etc/php/conf.d/gd.ini && \
    git config --global --add safe.directory /app

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/.composer \
    NPM_CONFIG_CACHE=/tmp/.npm \
    PATH="${PATH}:/app/.build/bin"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

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
