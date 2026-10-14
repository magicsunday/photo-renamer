#!/usr/bin/env bash
set -euo pipefail
benchmark_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
docker run --rm --network none --read-only --user "$(id -u):$(id -g)" \
    --cap-drop ALL --security-opt no-new-privileges:true \
    --memory 1536m --memory-swap 1536m --cpus 1 --pids-limit 128 \
    --tmpfs /tmp:size=256m,mode=1777,noexec,nosuid,nodev \
    --mount "type=bind,src=$benchmark_root/scripts,dst=/app/scripts,readonly" \
    --mount "type=bind,src=$benchmark_root/tests/Fixtures/Images/42-same-dir-format-backup,dst=/evaluation/regression42,readonly" \
    --mount "type=bind,src=$benchmark_root/tests/Fixtures/Images/59-minimal-edit-false-merge,dst=/evaluation/regression59,readonly" \
    --entrypoint timeout renamer-runtime \
    1800 php -d memory_limit=768M /app/scripts/benchmark-visual-classification.php
