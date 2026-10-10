#!/usr/bin/env bash
set -euo pipefail

cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."
benchmark_image="$(docker compose config --images buildbox)"

# No host credentials, network, writable repository or original collection.
# Keep fixture count, PHP/native memory, CPU, processes and temporary disk finite.
docker run --rm --network none --read-only \
    --memory 384m --memory-swap 384m --cpus 0.5 --pids-limit 64 \
    --cap-drop ALL --security-opt no-new-privileges \
    --user "$(id -u):$(id -g)" \
    --tmpfs /tmp:size=16m,mode=1777,noexec,nosuid,nodev \
    --volume "$PWD:/app:ro" --workdir /app --entrypoint php \
    "$benchmark_image" -d memory_limit=256M scripts/benchmark-video-comparisons.php "$@"
