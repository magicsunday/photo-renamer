# =============================================================================
# TARGETS
# =============================================================================

#### CI

.PHONY: test lint cgl-check rector-check stan unit coverage cpd audit mutation

test: .logo ## Runs the full CI pipeline (lint, cgl, rector, phpstan, phpunit, cpd).
	$(COMPOSE_BUILD) composer ci:test

lint: .logo ## Runs the PHP linter.
	$(COMPOSE_BUILD) composer ci:test:php:lint

cgl-check: .logo ## Checks the code style (dry-run).
	$(COMPOSE_BUILD) composer ci:test:php:cgl

rector-check: .logo ## Checks the rector rules (dry-run).
	$(COMPOSE_BUILD) composer ci:test:php:rector

stan: .logo ## Runs PHPStan analysis.
	$(COMPOSE_BUILD) composer ci:test:php:phpstan

unit: .logo ## Runs the PHPUnit tests.
	$(COMPOSE_BUILD) composer ci:test:php:unit

coverage: .logo ## Runs PHPUnit tests with HTML + Clover coverage report (.build/coverage/).
	-$(COMPOSE_BUILD) composer ci:test:php:unit:coverage

cpd: .logo ## Runs copy-paste detection (jscpd).
	$(COMPOSE_BUILD) composer ci:test:php:cpd

audit: .logo ## Checks for known security vulnerabilities in dependencies.
	$(COMPOSE_BUILD) composer ci:test:php:audit

mutation: .logo ## Runs mutation testing with Infection.
	-$(COMPOSE_BUILD) composer ci:test:php:mutation


#### Fix

.PHONY: cgl rector

cgl: .logo ## Fixes the code style.
	$(COMPOSE_BUILD) composer ci:cgl

rector: .logo ## Applies the rector rules.
	$(COMPOSE_BUILD) composer ci:rector


#### Dependencies

.PHONY: install update

install: .logo ## Installs the composer dependencies.
	$(COMPOSE_BUILD) composer install

update: .logo ## Updates the composer dependencies.
	$(COMPOSE_BUILD) composer update
