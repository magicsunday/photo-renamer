#!/usr/bin/env bash
set -euo pipefail
benchmark_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
profile="${1:-comparison}"
arguments=()
policy_mount=()
memory=1536m
tmp_size=256m
deadline=1800
case "$profile" in
    comparison) ;;
    regional)
        arguments+=(--regional)
        memory=3g
        tmp_size=1536m
        deadline=7200
        policy_file="$(mktemp)"
        trap 'rm -f -- "$policy_file"' EXIT
        sed -e 's/name="time" value="30"/name="time" value="300"/' \
            -e 's/name="memory" value="256MiB"/name="memory" value="768MiB"/' \
            -e 's/name="map" value="512MiB"/name="map" value="768MiB"/' \
            -e 's/name="disk" value="256MiB"/name="disk" value="512MiB"/' \
            "$benchmark_root/config/imagemagick-policy.xml" > "$policy_file"
        policy_mount+=(--mount "type=bind,src=$policy_file,dst=/etc/ImageMagick-7/policy.xml,readonly")
        ;;
    *) echo "Usage: $0 [comparison|regional]" >&2; exit 2 ;;
esac
docker run --rm --network none --read-only --user "$(id -u):$(id -g)" \
    --cap-drop ALL --security-opt no-new-privileges:true \
    --memory "$memory" --memory-swap "$memory" --cpus 1 --pids-limit 128 \
    --tmpfs "/tmp:size=$tmp_size,mode=1777,noexec,nosuid,nodev" \
    --mount "type=bind,src=$benchmark_root/scripts,dst=/app/scripts,readonly" \
    --mount "type=bind,src=$benchmark_root/tests/Fixtures/Images/42-same-dir-format-backup,dst=/evaluation/regression42,readonly" \
    --mount "type=bind,src=$benchmark_root/tests/Fixtures/Images/59-minimal-edit-false-merge,dst=/evaluation/regression59,readonly" \
    "${policy_mount[@]}" \
    --entrypoint timeout renamer-runtime \
    "$deadline" php -d memory_limit=768M /app/scripts/benchmark-visual-classification.php "${arguments[@]}"
