#!/usr/bin/env bash

# Verify bytes before any SPC execution, including reuse of a cached tool.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
source config/build-inputs.env
spc_target="${1:-$PWD/.build/bin/spc}"

if [[ ! "${SPC_VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ || \
    ! "${SPC_ARCHIVE_SHA256}" =~ ^[0-9a-f]{64}$ || \
    ! "${SPC_BINARY_SHA256}" =~ ^[0-9a-f]{64}$ ]]; then
    echo "Invalid versioned SPC checksum configuration." >&2
    exit 1
fi

verify_checksum() {
    local file="$1"
    local expected="$2"
    local actual
    if [[ ! -f "${file}" || -L "${file}" ]]; then
        echo "SPC checksum requires a regular non-symlinked file." >&2
        return 1
    fi
    # Stdin hashing avoids leaking filenames and handles spaces/control bytes.
    actual="$(sha256sum < "${file}")"
    if [[ "${actual%% *}" != "${expected}" ]]; then
        echo "SPC checksum mismatch; unverified bytes will not be executed." >&2
        return 1
    fi
}

if [[ -e "${spc_target}" || -L "${spc_target}" ]]; then
    verify_checksum "${spc_target}" "${SPC_BINARY_SHA256}"
    exit 0
fi

mkdir -p "$(dirname "${spc_target}")"
spc_temporary="$(mktemp -d "$(dirname "${spc_target}")/spc-download.XXXXXX")"
trap 'rm -rf -- "${spc_temporary}"' EXIT
curl --fail --location --max-time 120 --output "${spc_temporary}/spc.tar.gz" \
    "https://github.com/crazywhalecc/static-php-cli/releases/download/${SPC_VERSION}/spc-linux-x86_64.tar.gz"
verify_checksum "${spc_temporary}/spc.tar.gz" "${SPC_ARCHIVE_SHA256}"
# The checked official archive contains only this file. Extract that member,
# then separately verify the executable bytes before granting execution mode.
tar -xzf "${spc_temporary}/spc.tar.gz" -C "${spc_temporary}" spc
verify_checksum "${spc_temporary}/spc" "${SPC_BINARY_SHA256}"
chmod 755 "${spc_temporary}/spc"
mv -- "${spc_temporary}/spc" "${spc_target}"
