#!/usr/bin/env bash

# Run inside the Docker builder; native command startup must not depend on CACHE_DIR.
set -euo pipefail
binary_path="$(realpath "${1:?Pass the built standalone executable}")"
probe_root="$(mktemp -d "${TMPDIR:-/tmp}/renamer-native-state.XXXXXX")"
trap 'rm -rf -- "${probe_root}"' EXIT
mkdir -p "${probe_root}/home" "${probe_root}/temp" "${probe_root}/cwd" "${probe_root}/media"
printf '%s' 'synthetic native command bytes' > "${probe_root}/expected"
cp "${probe_root}/expected" "${probe_root}/media/PHOTO.JPG"
cd "${probe_root}/cwd"
printf 'yes\n' | env -u CACHE_DIR HOME="${probe_root}/home" \
    "${binary_path}" rename:lower --apply "${probe_root}/media" --no-ansi > "${probe_root}/default.log" 2>&1
cmp "${probe_root}/expected" "${probe_root}/media/photo.jpg"
[[ ! -e "${probe_root}/media/PHOTO.JPG" && -d "${probe_root}/home/.cache/renamer/locks" ]]
[[ -n "$(find "${probe_root}/home/.cache/renamer" -name 'mutation-*.jsonl' -print -quit)" ]]
printf 'yes\n' | env -u CACHE_DIR -u HOME TMPDIR="${probe_root}/temp" \
    "${binary_path}" rename:lower --apply "${probe_root}/media" --no-ansi > "${probe_root}/no-home.log" 2>&1
[[ -d "${probe_root}/temp/renamer-cache/locks" ]]
printf '%s' 'synthetic non-directory' > "${probe_root}/blocked-state"
if env CACHE_DIR="${probe_root}/blocked-state" "${binary_path}" rename:lower --apply "${probe_root}/media" --no-ansi > "${probe_root}/failure.log" 2>&1; then
    cat "${probe_root}/failure.log" >&2
    echo 'A blocked state directory unexpectedly succeeded.' >&2
    exit 1
else
    probe_status="$?"
fi
[[ "${probe_status}" -eq 1 ]]
if grep -Eq 'Fatal error:|internal corruption|zlib: data error' "${probe_root}/failure.log"; then
    cat "${probe_root}/failure.log" >&2
    exit 1
fi
cmp "${probe_root}/expected" "${probe_root}/media/photo.jpg"
echo 'Native artifact default state, actual rename, no-HOME fallback and failure-path autoload passed.'
