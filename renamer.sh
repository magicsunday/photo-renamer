#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"

if [[ $# -eq 0 ]]; then
    echo "Usage: ./renamer.sh <command> [options] <source-directory>" >&2
    echo "" >&2
    echo "Examples:" >&2
    echo "  ./renamer.sh rename:exif --dry-run ~/Photos" >&2
    echo "  ./renamer.sh rename:verify ~/Photos" >&2
    echo "  ./renamer.sh rename:dedup --dry-run ~/Photos" >&2
    exit 1
fi

# Determine docker compose binary
if command -v "docker-compose" &>/dev/null; then
    COMPOSE_BIN="docker-compose"
else
    COMPOSE_BIN="docker compose"
fi

$COMPOSE_BIN run --rm buildbox php -d memory_limit=-1 src/Renamer.php "$@"
