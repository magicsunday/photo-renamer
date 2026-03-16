# Development stage
FROM php:8.5-cli-alpine AS dev

RUN apk add --no-cache \
    bash \
    git \
    nodejs \
    npm \
    openssh-client

RUN git config --global --add safe.directory /app

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PATH="${PATH}:/app/.build/bin"

WORKDIR /app

# SPC builder stage
FROM ubuntu:latest AS builder

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
    make \
    openssl \
    patchelf \
    re2c \
    sudo \
    unzip \
    wget \
    zip && \
    rm -rf /var/lib/apt/lists/*

RUN (groupadd --gid ${GROUPID} renamer 2>/dev/null || groupmod -n renamer $(getent group ${GROUPID} | cut -d: -f1)) && \
    useradd --uid ${USERID} --gid ${GROUPID} --create-home renamer && \
    echo "renamer ALL=(ALL) NOPASSWD:ALL" >> /etc/sudoers
