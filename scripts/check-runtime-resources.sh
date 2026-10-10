#!/usr/bin/env bash

# Exercise the production artifact using only tiny synthetic local fixtures.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

mkdir -p .build/tmp
work_directory="$(mktemp -d "$PWD/.build/tmp/runtime-contract.XXXXXX")"
contract_container="renamer-${work_directory##*/}"

cleanup() {
    docker rm --force --volumes "${contract_container}" >/dev/null 2>&1 || true
    # Generated inputs and logs belong exclusively to this invocation.
    docker compose run --rm --user root --volume /dev/null:/ssh-agent \
        -e COMPOSER_AUTH= -e SSH_AUTH_SOCK= buildbox \
        rm -rf "/app/.build/tmp/${work_directory##*/}" >/dev/null 2>&1 || true
}
trap cleanup EXIT

# Fixture generation is trusted development work, with credentials suppressed.
docker compose run --rm --user root --volume /dev/null:/ssh-agent \
    -e COMPOSER_AUTH= -e SSH_AUTH_SOCK= buildbox \
    php /app/scripts/runtime-resources-smoke.php generate "/app/.build/tmp/${work_directory##*/}/media"
chmod 755 "${work_directory}"

sed -e 's/name="width" value="32768"/name="width" value="32"/' \
    -e 's/name="height" value="32768"/name="height" value="32"/' \
    -e 's/name="list-length" value="64"/name="list-length" value="2"/' \
    config/imagemagick-policy.xml > "${work_directory}/low-dimensions.xml"
sed -e 's/name="memory" value="256MiB"/name="memory" value="0"/' \
    -e 's/name="map" value="512MiB"/name="map" value="0"/' \
    -e 's/name="disk" value="256MiB"/name="disk" value="1024"/' \
    config/imagemagick-policy.xml > "${work_directory}/low-disk.xml"

# Set dummy host values explicitly: an unset-host-only check would miss leakage.
export COMPOSER_AUTH='{"github-oauth":{"github.com":"synthetic-not-a-token"}}'
export SSH_AUTH_SOCK=/dev/null
export MEDIA_DIR="${work_directory}/media"
export MEDIA_READ_ONLY=true

docker compose run --rm buildbox php -r '
    if (!str_contains(getenv("COMPOSER_AUTH") ?: "", "synthetic-not-a-token")
        || getenv("SSH_AUTH_SOCK") !== "/ssh-agent") {
        throw new RuntimeException("Development credential forwarding regressed.");
    }
    echo "Development credential forwarding preserved with dummy values.\n";
'
docker compose run --rm buildbox composer --version --no-ansi

docker compose run --rm -e CACHE_DIR=/tmp/synthetic-cache --entrypoint php \
    runtime /app/scripts/runtime-resources-smoke.php verify /media

# Inspect the actual running container, not just the Compose text.
docker compose run --no-deps -d --name "${contract_container}" --volume /media \
    --entrypoint sleep runtime 120 >/dev/null
configuration="$(docker inspect --format '{{.HostConfig.Memory}}|{{.HostConfig.MemorySwap}}|{{.HostConfig.NanoCpus}}|{{.HostConfig.PidsLimit}}|{{.HostConfig.ReadonlyRootfs}}|{{.HostConfig.NetworkMode}}|{{index .HostConfig.Tmpfs "/tmp"}}|{{json .HostConfig.CapDrop}}|{{json .HostConfig.SecurityOpt}}' "${contract_container}")"
IFS='|' read -r memory swap cpus pids readonly network temporary capabilities security <<< "${configuration}"
[[ "${memory}" -gt 0 && "${swap}" -eq "${memory}" && "${cpus}" -gt 0 && "${pids}" -gt 0 ]]
[[ "${readonly}" = true && "${network}" = none ]]
[[ "${temporary}" = *size=* && "${temporary}" = *noexec* && "${temporary}" = *nosuid* && "${temporary}" = *nodev* ]]
[[ "${capabilities}" = *ALL* && "${security}" = *no-new-privileges* ]]
echo "Actual container RAM/swap/CPU/PIDs, bounded tmpfs, read-only root and network/capability isolation verified."

# Apply smaller limits to the same production image; payloads remain <=64 px.
for mode in low-limits low-disk; do
    policy=low-dimensions.xml
    if [[ "${mode}" = low-disk ]]; then
        policy=low-disk.xml
    fi
    RUNTIME_MEMORY_LIMIT=384m RUNTIME_CPUS=0.5 RUNTIME_PIDS_LIMIT=64 RUNTIME_TMP_SIZE=16m \
        docker compose run --rm -e CACHE_DIR=/tmp/synthetic-cache \
        --volume "${work_directory}/${policy}:/etc/ImageMagick-7/policy.xml:ro" \
        --entrypoint php runtime /app/scripts/runtime-resources-smoke.php "${mode}" /media
done

docker compose run --rm -e CACHE_DIR=/tmp/synthetic-cache --entrypoint php \
    runtime /app/scripts/clear-cache.php --media-only

# Wrapper argument preservation, including an existing filename with spaces.
./renamer.sh rename:verify '/media/filename with spaces.jpg' > "${work_directory}/wrapper.log"

# Grant only the configured UID ownership of the synthetic directory, preserving
# 0755 instead of broadening host media permissions. Mutate a dedicated child.
runtime_user="$(docker inspect --format '{{.Config.User}}' "${contract_container}")"
docker compose run --rm --user root buildbox \
    chown "${runtime_user}" "/app/.build/tmp/${work_directory##*/}/media"
MEDIA_READ_ONLY=false docker compose run --rm -e CACHE_DIR=/tmp/synthetic-cache \
    --entrypoint sh runtime -c '
    mkdir /media/synthetic-rename
    cp /media/2024-01-01_12-00-00-000.jpg /media/synthetic-rename/UPPER.JPG
    printf "yes\n" | php /app/src/Renamer.php rename:lower /media/synthetic-rename >/dev/null
    test -f /media/synthetic-rename/upper.jpg
    test ! -e /media/synthetic-rename/UPPER.JPG
'

echo "Runtime resource/isolation contract passed with synthetic local media only."
