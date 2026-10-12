#!/usr/bin/env bash

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
bash scripts/check-lock.sh
# The download/bootstrap phase is separate from the credential-free app build.
bash scripts/init-with-docker
export USERID="$(id -u)" GROUPID="$(id -g)"
docker compose run --rm -e GITHUB_TOKEN= -e GH_TOKEN= -e COMPOSER_AUTH= \
    -e APP_REVISION="${APP_REVISION:-$(git rev-parse HEAD)}" -e APP_SOURCE_DIRTY="${APP_SOURCE_DIRTY:-1}" builder bash scripts/build
