FROM ubuntu:latest

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
    sudo \
    unzip \
    wget \
    zip

RUN rm -rf /var/lib/apt/lists/*

RUN set -ex && \
    groupadd --gid ${GROUPID} renamer && \
    useradd --uid ${USERID} --gid renamer --create-home renamer && \
    echo "renamer ALL=(ALL) NOPASSWD:ALL" >> /etc/sudoers
