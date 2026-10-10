#!/usr/bin/env bash
set -euo pipefail

cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."
benchmark_runtime_image="$(docker compose config --images runtime)"

# Use the rebuilt production image. Mount only these scripts, never a collection,
# the development vendor tree, repository credentials or the private cache volume.
# The LP-heavy synthetic profile uses real tiny files, not camera-size payloads.
docker run --rm --network none --read-only \
    --memory 4g --memory-swap 4g --cpus 1 --pids-limit 128 \
    --cap-drop ALL --security-opt no-new-privileges \
    --user "$(id -u):$(id -g)" \
    --tmpfs /tmp:size=1g,mode=1777,noexec,nosuid,nodev \
    --tmpfs /probe-bin:size=1m,mode=1777,exec,nosuid,nodev \
    --volume "$PWD/scripts:/bench:ro" \
    --env BENCHMARK_PROJECT_ROOT=/app --env BENCHMARK_NATIVE_BIN=/probe-bin \
    --env MAX_COMPARISON_PAIRS=100000 \
    --entrypoint php "$benchmark_runtime_image" \
    -d memory_limit=256M /bench/benchmark-mixed-collection.php profile "${1:-70000}"
