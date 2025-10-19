<!-- Managed by agent: keep sections & order; edit content, not structure. Last updated: 2024-05-29 -->

# Overview
- Holds service configuration for the Symfony DependencyInjection container (currently `Services.yaml`).
- Changes here directly impact how CLI commands and services are wired; keep definitions declarative and synchronized with `src/` abstractions.

# Setup / Environment
- Service definitions assume autowiring/autoconfiguration enabled via Symfony's container builder in `src/Dependencies.php`.
- After editing YAML files, regenerate the cached container via the build scripts (`make init` or `bin/php vendor/bin/console` equivalents) instead of manual cache edits.

# Build & Tests
- When modifying service wiring, run `composer run ci:test:php:unit` to ensure integration coverage still passes.
- If constructor signatures change, run `composer run ci:test:php:phpstan` to catch autowiring issues early.

# Code Style
- Follow Symfony YAML conventions: two-space indentation, kebab-case service IDs, and use `bind:` for scalar configuration values.
- Keep comments documenting non-obvious parameters or tags; prefer referencing class constants for default values.

# Security
- Never hard-code credentials or file paths; rely on environment variables or parameters injected at runtime.
- Avoid enabling autowiring for classes that operate on user-provided paths without validation hooks.

# PR / Commit Checklist
- Verify that every new service has corresponding unit/integration coverage and is registered in autoload namespaces if needed.
- Update documentation (README or scoped AGENTS) when introducing new parameters or tags.

# Good vs Bad Examples
- ✅ `config/Services.yaml` shows clean grouping of command definitions and service tags.
- 🚫 Avoid embedding environment-specific file paths directly in YAML; use parameters or configuration objects instead.

# When Stuck
- Compare against Symfony's official DI documentation for syntax; the project intentionally mirrors standard practices.
- Inspect `src/Dependencies.php` to see how YAML services are loaded and compiled into the cached container.

# House Rules
- No additional overrides beyond the repository defaults; keep configuration minimal and declarative.
