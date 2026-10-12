#!/usr/bin/env bash

# A local owned cache receipt detects stale inputs and damaged build outputs.
# It is not a signature or protection against a hostile workspace owner.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
bootstrap_state=.build/spc/bootstrap.sha256
bootstrap_files=(config/build-inputs.env Dockerfile scripts/init scripts/ensure-spc.sh scripts/bootstrap-state.sh scripts/native-inputs.sh .build/spc/native-inputs.sha256 .build/bin/spc /usr/bin/composer .build/spc/buildroot/bin/php .build/spc/buildroot/bin/micro.sfx)
bootstrap_snapshot=
for bootstrap_file in "${bootstrap_files[@]}"; do
    if [[ ! -f "${bootstrap_file}" || -L "${bootstrap_file}" ]]; then
        echo "Bootstrap input/output missing or linked; rebuild the owned bootstrap cache." >&2
        exit 1
    fi
    bootstrap_hash="$(sha256sum < "${bootstrap_file}")"
    bootstrap_snapshot+="${bootstrap_hash%% *} ${bootstrap_file}"$'\n'
done
case "${1:-verify}" in
    record)
        mkdir -p .build/spc
        printf '%s' "${bootstrap_snapshot}" > "${bootstrap_state}"
        chmod 600 "${bootstrap_state}"
        ;;
    verify)
        if [[ ! -f "${bootstrap_state}" || -L "${bootstrap_state}" || "$(cat "${bootstrap_state}")" != "${bootstrap_snapshot%$'\n'}" ]]; then
            echo "Bootstrap inputs/output checksum changed; rebuild the owned bootstrap cache." >&2
            exit 1
        fi
        ;;
    *) echo "Expected bootstrap-state.sh record or verify." >&2; exit 1 ;;
esac
