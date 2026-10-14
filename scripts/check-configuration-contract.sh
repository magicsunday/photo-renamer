#!/usr/bin/env bash

# Prove Compose arrival separately from actual CLI policy consumption.
# Only tiny generated media in an external directory are ever mounted.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

configuration_workspace="$(mktemp -d "${TMPDIR:-/tmp}/renamer configuration.XXXXXX")"
signal_container=''

# Remove only this check's disposable signal process and synthetic workspace.
cleanup_configuration_workspace() {
    if [[ -n "${signal_container}" ]]; then
        docker rm --force "${signal_container}" > /dev/null 2>&1 || true
    fi
    rm -rf -- "${configuration_workspace}"
}
trap cleanup_configuration_workspace EXIT
chmod 755 "${configuration_workspace}"
export MEDIA_DIR="${configuration_workspace}/media with spaces"
export MEDIA_READ_ONLY=true
export USERID="$(id -u)" GROUPID="$(id -g)"
export COMPOSER_AUTH=
export SSH_AUTH_SOCK=/dev/null
export TIMEZONE=UTC
export MAX_DATE_DRIFT=0
export MERGE_THRESHOLD=0.01
export CANONICAL_FORMAT_PRIORITY=jpg,heic

# The empty env file models a fresh checkout without touching user configuration.
# This development-only fixture mount is absent from every production CLI run.
docker compose --env-file /dev/null run --rm -T --user "$(id -u):$(id -g)" \
    --volume "${configuration_workspace}:/configuration-fixtures" buildbox php <<'PHP'
<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Scripts;

use Imagick;
use ImagickPixel;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

use function str_ends_with;

require '/app/.build/vendor/autoload.php';

$filesystem = new Filesystem();
$root = '/configuration-fixtures/media with spaces';

foreach (['canonical' => ['same a.jpg' => '#808080', 'same b.heic' => '#808080'], 'merge' => ['same a.jpg' => '#808080', 'same b.jpg' => '#868686']] as $directory => $files) {
    $filesystem->mkdir($root . '/' . $directory);

    foreach ($files as $name => $color) {
        $path = $root . '/' . $directory . '/' . $name;
        $image = new Imagick();

        try {
            $image->newImage(64, 64, new ImagickPixel($color));
            $image->setImageFormat(str_ends_with($name, '.heic') ? 'heic' : 'jpeg');
            $image->writeImage($path);
        } finally {
            $image->clear();
        }

        new Process(['exiftool', '-overwrite_original', '-DateTimeOriginal=2024:01:01 12:00:00', '-SubSecTimeOriginal=000', $path])->mustRun();
    }
}

$filesystem->copy($root . '/canonical/same a.jpg', $root . '/filename with spaces.jpg');
PHP

media_digest() {
    (cd "${MEDIA_DIR}" && sha256sum canonical/* merge/* 'filename with spaces.jpg')
}
initial_media_digest="$(media_digest)"

for service in buildbox runtime; do
    docker compose --env-file /dev/null run --rm --entrypoint php "${service}" -r '
        foreach (["CANONICAL_FORMAT_PRIORITY" => "jpg,heic", "MERGE_THRESHOLD" => "0.01", "TIMEZONE" => "UTC", "MAX_DATE_DRIFT" => "0"] as $key => $expected) {
            if (getenv($key) !== $expected) {
                throw new RuntimeException("Compose did not forward the selected " . $key . ".");
            }
        }
        echo "Explicit Compose policy arrival verified.\n";
    '
done

# Logs are tiny, synthetic and separate from the application's private caches.
run_exif() {
    local profile="$1"
    local source="$2"
    shift 2
    docker compose --env-file /dev/null run --rm -e CACHE_DIR=/tmp/configuration-cache \
        runtime rename:exif "${source}" --dry-run --list-all --no-ansi "$@" \
        > "${configuration_workspace}/${profile}.log" 2>&1
}

# The command's observable canonical selection must change with the host value.
export MERGE_THRESHOLD=0.06
run_exif jpg-priority /media/canonical
[[ "$(<"${configuration_workspace}/jpg-priority.log")" = *'Canonical: same a.jpg'* ]]
export CANONICAL_FORMAT_PRIORITY=heic,jpg
run_exif heic-priority /media/canonical
[[ "$(<"${configuration_workspace}/heic-priority.log")" = *'Canonical: same b.heic'* ]]

# Native pixel evidence is about 6/255 RMSE: the default policy accepts it,
# while the smaller selected threshold rejects the same pair.
run_exif default-merge /media/merge
[[ "$(<"${configuration_workspace}/default-merge.log")" = *'perceptual: visual similarity within merge threshold'* ]]
[[ "$(<"${configuration_workspace}/default-merge.log")" = *'threshold=0.0600'* ]]
export MERGE_THRESHOLD=0.01
run_exif strict-merge /media/merge
[[ "$(<"${configuration_workspace}/strict-merge.log")" = *'different: pixel difference exceeds merge threshold'* ]]
[[ "$(<"${configuration_workspace}/strict-merge.log")" = *'threshold=0.0100'* ]]

# Selected CLI values take precedence even when the shadowed ENV is invalid.
export TIMEZONE=Invalid/Zone MAX_DATE_DRIFT=abc MERGE_THRESHOLD=abc
run_exif cli-precedence /media/merge --timezone=UTC --max-date-drift=0 --merge-threshold=0.01
[[ "$(<"${configuration_workspace}/cli-precedence.log")" = *'threshold=0.0100'* ]]

# Invalid selected environment values fail visibly in the immutable artifact.
export TIMEZONE=UTC MAX_DATE_DRIFT=0 MERGE_THRESHOLD=0.06
for invalid_key in TIMEZONE MAX_DATE_DRIFT MERGE_THRESHOLD; do
    export "${invalid_key}=invalid"
    if run_exif "invalid-${invalid_key}" /media/merge; then
        echo "Invalid ${invalid_key} was accepted by the CLI." >&2
        exit 1
    else
        invalid_status="$?"
    fi
    [[ "${invalid_status}" -eq 1 ]]
    [[ "$(<"${configuration_workspace}/invalid-${invalid_key}.log")" = *"${invalid_key} / --"* ]]
    export TIMEZONE=UTC MAX_DATE_DRIFT=0 MERGE_THRESHOLD=0.06
done

# Fresh-checkout Compose defaults remain usable without an .env file.
unset TIMEZONE MAX_DATE_DRIFT MERGE_THRESHOLD CANONICAL_FORMAT_PRIORITY
docker compose --env-file /dev/null run --rm --entrypoint php runtime -r '
    foreach (["TIMEZONE" => "Europe/Berlin", "MAX_DATE_DRIFT" => "7", "MERGE_THRESHOLD" => "0.06"] as $key => $expected) {
        if (getenv($key) !== $expected) {
            throw new RuntimeException("Fresh-checkout default changed for " . $key . ".");
        }
    }
'
run_exif fresh-defaults /media/merge
[[ "$(<"${configuration_workspace}/fresh-defaults.log")" = *'perceptual: visual similarity within merge threshold'* ]]

# Exercise the documented wrapper with both an external host directory and a
# filename containing spaces. This simple dry run has no metadata-cache writes.
export TIMEZONE=UTC MAX_DATE_DRIFT=0 MERGE_THRESHOLD=0.06
./renamer.sh rename:lower --dry-run --no-ansi '/media/filename with spaces.jpg' \
    > "${configuration_workspace}/wrapper.log" 2>&1
[[ "$(<"${configuration_workspace}/wrapper.log")" = *'[OK] done'* ]]
[[ -f "${MEDIA_DIR}/filename with spaces.jpg" && -f "${MEDIA_DIR}/canonical/same a.jpg" && -f "${MEDIA_DIR}/canonical/same b.heic" ]]

# Exercise the actual runtime service: PID 1 must forward both interruption
# signals to PHP. Readiness and exit checks are bounded; no media is processed.
for signal in INT TERM; do
    signal_container="renamer-signal-${configuration_workspace##*.}-${signal}"
    docker compose --env-file /dev/null run --detach --name "${signal_container}" \
        --entrypoint php runtime -r 'echo "ready\n"; while (true) { usleep(100000); }' > /dev/null

    ready=false
    for attempt in {1..50}; do
        if [[ "$(docker logs "${signal_container}")" = ready ]]; then
            ready=true
            break
        fi
        sleep 0.1
    done
    [[ "${ready}" = true ]]

    docker kill --signal "${signal}" "${signal_container}" > /dev/null
    stopped=false
    for attempt in {1..50}; do
        if [[ "$(docker inspect --format '{{.State.Running}}' "${signal_container}")" = false ]]; then
            stopped=true
            break
        fi
        sleep 0.1
    done
    [[ "${stopped}" = true ]]

    expected_status=130
    if [[ "${signal}" = TERM ]]; then
        expected_status=143
    fi
    [[ "$(docker inspect --format '{{.State.ExitCode}}' "${signal_container}")" = "${expected_status}" ]]
    docker rm "${signal_container}" > /dev/null
    signal_container=''
done

[[ "$(media_digest)" = "${initial_media_digest}" ]]
echo "Docker configuration, CLI policies, paths with spaces and interruption signals verified; all synthetic media names and bytes preserved."
