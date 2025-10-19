<!-- Managed by agent: keep sections & order; edit content, not structure. Last updated: 2024-05-29 -->

# Overview
- PHP source for the CLI, organized by Console commands, services, models, and strategies; all production code here must remain framework-agnostic beyond Symfony Console/DI usage.
- Respect existing container wiring defined in `config/Services.yaml` and helper factories in `src/Dependencies.php`.

# Setup / Environment
- Ensure `composer install` has been executed so the Symfony components and autoloader are available.
- When adding new services, register them in `config/Services.yaml` and, if needed, regenerate the cached container (`var/cache/DependencyContainer.php`) via the existing build scripts instead of manual edits.

# Build & Tests
- Add or update unit tests under `test/Unit` to cover new code paths; run `composer run ci:test:php:unit` before committing changes touching `src/`.
- Static analysis is mandatory: run `composer run ci:test:php:phpstan` (level 10) and ensure no new baseline entries are introduced.

# Code Style
- Follow the strict typing pattern seen in `src/Application.php` (`declare(strict_types=1);`, promoted properties, typed constants).
- Use constructor injection or service locators supplied by the DI container; avoid `new`ing dependencies inline (see `src/Service/DuplicateDetectionService.php` for a good example).
- Keep command I/O interactions localized within `SymfonyStyle` helpers; reuse formatting utilities from `src/Command/AbstractRenameCommand.php` where possible.

# Security
- Validate all filesystem paths received from user input using existing filter iterators (`src/Command/FilterIterator/...`) before acting on them.
- Never disable the duplication checks or pattern validation guards—extend strategies by composition, not by bypassing them.

# PR / Commit Checklist
- New features must include updated service definitions (if applicable), phpstan-clean code, and regression tests demonstrating behavior.
- Update documentation strings and console command descriptions when modifying CLI arguments or options.

# Good vs Bad Examples
- ✅ `src/Command/AbstractRenameCommand.php` demonstrates how to configure Symfony console commands with shared helpers.
- ✅ `src/Service/DuplicateDetectionService.php` illustrates dependency injection and clear return types.
- 🚫 Do not replicate the bootstrapping logic from `src/Renamer.php` inside commands; instead, request dependencies through the container.
- 🚫 Avoid suppressing phpstan warnings locally—fix the underlying type issues or adjust shared abstractions.

# When Stuck
- Review `src/Dependencies.php` to understand available shared services and how they are constructed.
- Search the `test/Unit` suite for analogous scenarios before creating new abstractions.

# House Rules
- No additional overrides; follow repository defaults alongside these PHP-specific expectations.
