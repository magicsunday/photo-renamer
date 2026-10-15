#!/usr/bin/env bash

# Full native-artifact contracts use the runtime's external media tools and isolation.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
binary_path="$(realpath "${1:-./renamer}")"
runtime_image="$(docker compose --profile runtime config --images | grep 'runtime$' | head -n 1)"
docker run --rm --init --network none --read-only --cap-drop ALL \
    --security-opt no-new-privileges:true --user "$(id -u):$(id -g)" \
    --memory 2g --memory-swap 2g --cpus 2 --pids-limit 128 \
    --tmpfs /tmp:rw,noexec,nosuid,nodev,size=256m,mode=1777 \
    --mount "type=bind,source=${binary_path},target=/artifact/renamer,readonly" \
    --mount "type=bind,source=${PWD}/scripts/check-command-contracts.php,target=/checks/commands.php,readonly" \
    --mount "type=bind,source=${PWD}/tests/Fixtures/Images,target=/fixtures,readonly" \
    --entrypoint php "${runtime_image}" /checks/commands.php \
        /app/.build/vendor/autoload.php /fixtures /artifact/renamer
