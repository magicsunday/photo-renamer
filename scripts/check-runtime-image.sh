#!/usr/bin/env bash

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

MIN_FFMPEG_VERSION="${MIN_FFMPEG_VERSION:-8.1.2-r0}"
IMAGE_NAME="$(docker compose --profile runtime config --images | grep 'runtime$' | head -n 1)"
IMAGE_ID="$(docker image inspect "${IMAGE_NAME}" --format '{{.Id}}')"

if [ "${IMAGE_NAME}" = "" ] || [ "${IMAGE_ID}" = "" ]; then
    echo "The isolated runtime image is not available." >&2
    exit 1
fi

echo "Runtime image: ${IMAGE_NAME} (${IMAGE_ID})"
docker compose run --rm --volume /media -e CACHE_DIR=/tmp/synthetic-cache \
    --entrypoint bash runtime /app/scripts/runtime-image-smoke.sh "${MIN_FFMPEG_VERSION}"
bash scripts/check-runtime-resources.sh

echo "Runtime image verification passed"
