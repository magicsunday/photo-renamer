#!/usr/bin/env bash

# Apply the production packaging exclusions to an explicitly supplied vendor tree.
set -euo pipefail
vendor_directory="${1:?Pass the isolated production vendor directory}"

# Non-essential vendor directories removed before PHAR creation
STRIP_DIRS=(docs doc tests test test-images scripts Make .github examples)

# Non-essential vendor files removed before PHAR creation
STRIP_FILES=(
    'README*' 'LICENSE*' 'CHANGELOG*' 'CONTRIBUTING*' 'AGENTS.md' 'CLAUDE.md'
    '.gitignore' '.gitattributes' 'phpunit.xml*' 'phpstan*' 'rector*'
    '.php-cs-fixer*' 'Makefile' 'composer.json' 'composer.lock'
)

dir_args=()
for dir in "${STRIP_DIRS[@]}"; do
    dir_args+=(-o -name "$dir")
done
# Remove leading -o
find "${vendor_directory}" -mindepth 3 -maxdepth 3 -type d \
    \( "${dir_args[@]:1}" \) -exec rm -rf {} +

file_args=()
for file in "${STRIP_FILES[@]}"; do
    file_args+=(-o -iname "$file")
done
find "${vendor_directory}" -mindepth 3 -maxdepth 3 -type f \
    \( "${file_args[@]:1}" \) -delete
