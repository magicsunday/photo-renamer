# Development stage
FROM php:8.5-cli-alpine AS dev

RUN apk add --no-cache \
    bash \
    git \
    nodejs \
    npm \
    openssh-client \
    perl \
    exiftool && \
    git config --global --add safe.directory /app

ENV COMPOSER_ALLOW_SUPERUSER=1 \
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
    echo "renamer ALL=(ALL) NOPASSWD: /usr/bin/apt-get, /usr/bin/apt" >> /etc/sudoers
