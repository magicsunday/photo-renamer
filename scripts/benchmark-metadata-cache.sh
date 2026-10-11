#!/usr/bin/env bash
# Isolated synthetic cache profile; never accepts an original media directory.
set -euo pipefail

collection_count="${1:-100000}"
if [[ ! "$collection_count" =~ ^[0-9]+$ ]] || (( 10#$collection_count < 1000 || 10#$collection_count > 200000 )); then
    printf '%s\n' 'Use a synthetic entry count between 1000 and 200000.' >&2
    exit 2
fi

project_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
docker run --rm --network none --read-only --user 1000:1000 \
    --cap-drop ALL --security-opt no-new-privileges:true \
    --memory 2g --memory-swap 2g --cpus 1 --pids-limit 128 \
    --tmpfs /tmp:size=256m,mode=1777,noexec,nosuid,nodev \
    --mount "type=bind,src=$project_root/scripts,dst=/app/scripts,readonly" \
    --entrypoint timeout renamer-runtime \
    300 php -d memory_limit=1024M /app/scripts/benchmark-metadata-cache.php "$collection_count"
