<!-- Managed by agent: keep sections & order; edit content, not structure. Last updated: 2024-05-29 -->

# Overview
- This repository contains the PHP 8.4 photo renaming CLI that relies on Symfony Console and DependencyInjection; follow scoped instructions for implementation-specific guidance (e.g., `src/AGENTS.md`, `test/AGENTS.md`, `config/AGENTS.md`).
- Precedence: instructions in child directories override root defaults; defer to the most specific `AGENTS.md` near the file you are editing.
- Decision Log (2024-05-29): Adopted composer scripts under the `ci:*` namespace as the canonical lint/test commands; documented Make targets that wrap `.build` scripts for binary packaging.

# Setup / Environment
- Require PHP ≥ 8.4 with the extensions listed in `.build/init` (dom, exif, yaml, etc.); run `make init` (or `make init-with-docker`) to provision the static PHP toolchain and Composer locally.
- Use `composer install` after setup; if `direnv` is available run `direnv allow` once to load `.envrc` for context messaging.
- Keep vendor dependencies synced with `composer.lock`; do not upgrade without coordinating via Renovate/Dependabot or a dedicated ticket.

# Build & Tests
- Fast local verification mirrors CI via composer scripts:
  - `composer run ci:test:php:lint`
  - `composer run ci:test:php:phpstan`
  - `composer run ci:test:php:cgl`
  - `composer run ci:test:php:unit`
- Use `make help` to discover additional project automation (e.g., packaging with `make build`).

# Code Style
- Global defaults follow PSR-12 with Symfony and PER-CS 2.0 refinements enforced via `.build/.php-cs-fixer.dist.php`; scoped files may add requirements.
- Prefer dependency injection via service definitions in `config/Services.yaml` rather than ad-hoc instantiation.

# Security
- Never commit secrets or generated binaries; `bin/` artifacts from `.build` scripts must stay out of version control unless explicitly whitelisted.
- Keep dependency scans (phpstan strict rules, Rector dry-runs) clean; record any temporary suppressions in the Decision Log with expiry plans.

# PR / Commit Checklist
- Use Conventional Commit prefixes (e.g., `feat:`, `fix:`, `chore:`); include ticket IDs when available.
- Before opening a PR, ensure you ran the composer `ci:test:*` scripts relevant to your changes and updated documentation/AGENTS when behavior or tooling shifts.
- Keep PRs ≤ ~300 net LOC and focused on a single concern.

# Good vs Bad Examples
- ✅ `src/Application.php` shows strict typing, dependency injection, and Symfony integration done correctly.
- ✅ `config/Services.yaml` documents how services are wired without hard-coding dependencies.
- 🚫 Avoid adding one-off CLI entrypoints that bypass `src/Renamer.php`; doing so fragments bootstrapping.
- 🚫 Avoid committing generated cache files under `.build/cache/`; these belong to the build output only.

# When Stuck
- Check `README.md` for high-level usage and release steps, and inspect `Make/helper/help.mk` for discoverable Make targets.
- If tooling errors arise, review `.build/init`/`init-with-docker` for environment expectations or rerun the init command.

# House Rules
- No additional root overrides; follow defaults unless a scoped `AGENTS.md` specifies otherwise.
