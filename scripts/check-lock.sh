#!/usr/bin/env bash

# Application installs must never turn missing release input into an update.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
if [[ ! -f composer.lock || ! -s composer.lock || ! -r composer.lock || -L composer.lock ]]; then
    echo "A regular, readable composer.lock is required; run an explicit reviewed dependency update instead of implicit resolution." >&2
    exit 1
fi
