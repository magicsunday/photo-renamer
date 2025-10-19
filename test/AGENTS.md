<!-- Managed by agent: keep sections & order; edit content, not structure. Last updated: 2024-05-29 -->

# Overview
- Contains PHPUnit test suites (currently unit tests under `test/Unit`) that exercise the photo renamer domain and CLI commands.
- Tests autoload via `composer.json` (`MagicSunday\\Renamer\\Test\\` namespace); follow the existing directory structure when adding scenarios.

# Setup / Environment
- Ensure `vendor/` is present by running `composer install`; PHPUnit boots via `.build/phpunit.xml`.
- Use the phpunit configuration defaults (strict mode, colors, memory limit) unless there is a documented reason to override them globally.

# Build & Tests
- Run `composer run ci:test:php:unit` for the full suite, or scope with `vendor/bin/phpunit --configuration .build/phpunit.xml --filter <TestName>` during development.
- Keep tests deterministic—avoid filesystem writes outside the temporary directories managed by the test harness.

# Code Style
- Follow naming conventions like `*Test.php` and method names starting with `test`.
- Prefer data providers (`@dataProvider`) for combinatorial cases as seen across the `test/Unit/Model/Pattern` tests.

# Security
- Do not load external fixtures from the network; rely on local fixtures or synthetic data builders.
- Sanitize any file paths used in mocks to stay within the test workspace.

# PR / Commit Checklist
- Every new behavior change in `src/` should include or update relevant tests here.
- Remove redundant fixtures and keep assertions focused on observable behavior (inputs/outputs/events).

# Good vs Bad Examples
- ✅ `test/Unit/Command/FilterIterator/RecursiveRegexFileFilterIteratorTest.php` demonstrates targeted coverage of iterators with clear expectations.
- ✅ `test/Unit/Model/Pattern/PatternExpressionTest.php` shows how to use data providers for thorough pattern checks.
- 🚫 Avoid large integration-style assertions in unit tests—keep them granular and mock dependencies when needed.
- 🚫 Do not rely on the cached container file in tests; bootstrap through PHPUnit fixtures or mocks instead.

# When Stuck
- Reference `.build/phpunit.xml` to understand the configured suites and environment options.
- Look at similar tests within `test/Unit` for patterns on stubbing services and using utility classes.

# House Rules
- No additional overrides; adhere to repository defaults while ensuring tests remain fast and reliable.
