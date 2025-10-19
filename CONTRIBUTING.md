# Contributing to Photo Renamer

Thanks for your interest in improving Photo Renamer! This document explains how to set up a development environment, run the test suite, and submit changes.

## Getting started

1. Clone the repository:
   ```bash
   git clone https://github.com/magicsunday/photo-renamer.git
   cd photo-renamer
   ```
2. Initialise the build toolchain (choose one):
   * **Docker (recommended):** `make init-with-docker`
   * **Local toolchain:** `make init`

   The initial setup downloads, compiles, and configures a statically linked PHP binary. Expect the first run to take roughly 10 minutes, consume ~4 GB RAM, and use about 1.5 GB of disk space.
3. Install dependencies:
   ```bash
   bin/composer install
   ```
4. Build the standalone binary:
   ```bash
   make build
   ```
   The resulting `renamer` binary is placed in the project root.

## Development workflow

* Enable `direnv` (if available) with `direnv allow` to load project-specific environment settings.
* Keep dependencies in sync with `composer.lock`; do not bump versions without discussing the change.
* Use dependency injection via `config/Services.yaml` instead of instantiating services manually.

## Testing and quality checks

Composer scripts mirror the CI pipeline:

```bash
bin/composer ci:test:php:lint      # Syntax checks
bin/composer ci:test:php:phpstan   # Static analysis
bin/composer ci:test:php:cgl       # Coding standards
bin/composer ci:test:php:unit      # Unit tests
```

Additional tooling is available through Make targets—run `make help` to list them.

## Submitting changes

1. Follow Conventional Commits (e.g. `feat:`, `fix:`, `docs:`) and reference an issue number when possible.
2. Update documentation or configuration files when you change behaviour or tooling.
3. Ensure CI scripts pass locally before opening a pull request.
4. Summarise the problem, your approach, and key testing notes in the PR description.

Need help? Open an issue or start a discussion on the repository.
