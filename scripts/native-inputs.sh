#!/usr/bin/env bash

# Record public downloaded native archives and exact Git/submodule identities.
# Do not include API response caches, credentials or absolute workspace paths.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.build/spc"
native_receipt=native-inputs.sha256
native_snapshot=
for native_path in downloads/* pkgroot/bin/upx; do
    [[ -e "${native_path}" ]] || continue
    if [[ -L "${native_path}" ]]; then echo "Native input must not be symlinked." >&2; exit 1; fi
    if [[ -f "${native_path}" ]]; then
        native_hash="$(sha256sum < "${native_path}")"
        native_snapshot+="sha256 ${native_hash%% *} ${native_path}"$'\n'
    elif [[ -d "${native_path}/.git" ]]; then
        native_commit="$(git -C "${native_path}" rev-parse HEAD)"
        native_snapshot+="git ${native_commit} ${native_path}"$'\n'
        native_submodules="$(git -C "${native_path}" submodule status --recursive)"
        if [[ -n "${native_submodules}" ]]; then native_snapshot+="${native_submodules}"$'\n'; fi
    else
        echo "Native source directory needs a recorded Git identity." >&2; exit 1
    fi
done
case "${1:-verify}" in
    record) printf '%s' "${native_snapshot}" > "${native_receipt}"; chmod 600 "${native_receipt}" ;;
    verify)
        if [[ ! -f "${native_receipt}" || -L "${native_receipt}" || "$(cat "${native_receipt}")" != "${native_snapshot%$'\n'}" ]]; then
            echo "Native input checksums changed; inspect the downloaded cache before compilation." >&2
            exit 1
        fi
        ;;
    *) echo "Expected native-inputs.sh record or verify." >&2; exit 1 ;;
esac
