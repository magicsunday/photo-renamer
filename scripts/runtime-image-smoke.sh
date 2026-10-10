#!/usr/bin/env bash

# Verify the decoder and non-mutating CLI inside the production image.

set -euo pipefail

REQUIRED_FFMPEG_VERSION="${1:?A minimum ffmpeg version is required}"
FFMPEG_PACKAGE="$(apk info -e -v ffmpeg)"
FFMPEG_VERSION="${FFMPEG_PACKAGE#ffmpeg-}"
FFMPEG_COMPARISON="$(apk version -t "${FFMPEG_VERSION}" "${REQUIRED_FFMPEG_VERSION}")"

printf 'ffmpeg-package=%s\n' "${FFMPEG_PACKAGE}"
printf 'ffmpeg-version=%s\n' "$(ffmpeg -version | sed -n '1s/.*version \([^ ]*\).*/\1/p')"
printf 'ffmpeg-comparison=%s\n' "${FFMPEG_COMPARISON}"

if [ "${FFMPEG_COMPARISON}" = "<" ]; then
    echo "Installed ffmpeg ${FFMPEG_VERSION} is older than required ${REQUIRED_FFMPEG_VERSION}." >&2
    exit 1
fi

echo "Running the harmless MagicYUV AVI dry-run"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "${WORK_DIR}"' EXIT

ffmpeg -hide_banner -loglevel error -y -f lavfi -i color=black:s=32x32:d=0.1 -frames:v 1 -c:v magicyuv "${WORK_DIR}/2024-01-01_00-00-00-000-a.avi"
ffmpeg -hide_banner -loglevel error -y -f lavfi -i color=white:s=32x32:d=0.1 -frames:v 1 -c:v magicyuv "${WORK_DIR}/2024-01-01_00-00-00-000-b.avi"

BEFORE_HASHES="$(sha256sum "${WORK_DIR}"/*.avi)"
php /app/src/Renamer.php rename:exif --dry-run "${WORK_DIR}" >/dev/null
AFTER_HASHES="$(sha256sum "${WORK_DIR}"/*.avi)"

test "${BEFORE_HASHES}" = "${AFTER_HASHES}"
