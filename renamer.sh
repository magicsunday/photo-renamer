#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"

if [[ $# -eq 0 ]]; then
    echo "Usage: ./renamer.sh <command> [options] <source-directory>" >&2
    echo "" >&2
    echo "Examples:" >&2
    echo "  MEDIA_DIR=~/Photos ./renamer.sh rename:exif --dry-run /media" >&2
    echo "  MEDIA_DIR=~/Photos ./renamer.sh rename:verify /media" >&2
    echo "  MEDIA_DIR=~/Photos ./renamer.sh rename:dedup --dry-run /media" >&2
    exit 1
fi

# Determine docker compose binary
if command -v "docker-compose" &>/dev/null; then
    COMPOSE_BIN="docker-compose"
else
    COMPOSE_BIN="docker compose"
fi

$COMPOSE_BIN run --rm runtime "$@"
