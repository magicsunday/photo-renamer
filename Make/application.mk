# =============================================================================
# Variables
# =============================================================================

DI_CACHE       = .build/cache/DependencyContainer.php

# =============================================================================
# TARGETS
# =============================================================================

#### Application

.PHONY: binary binary-init binary-clean cache-clear runtime-cache-clear cache-permissions-check comparison-benchmark mixed-collection-benchmark version

comparison-benchmark: .logo ## Benchmark 1,000 synthetic video candidates under finite Docker resource limits.
	bash scripts/benchmark-video-comparisons.sh

mixed-collection-benchmark: .logo ## Profile synthetic JPG/HEIC+MOV through the runtime pipeline (COUNT=70000 or 100000).
	bash scripts/benchmark-mixed-collection.sh $(or $(COUNT),70000)

binary: .logo ## Build the self-contained renamer binary.
	$(COMPOSE_BUILD) php scripts/clear-cache.php
	@bash scripts/build

binary-init: .logo ## Initialize SPC build environment (download + compile PHP).
	@bash scripts/init-with-docker

binary-clean: .logo ## Remove SPC build artifacts to free space.
	@rm -rf .build/spc/pkgroot/ .build/spc/downloads/ .build/spc/source/

cache-clear: .logo ## Clear development/legacy media caches and owned DI container.
	$(COMPOSE_BUILD) php scripts/clear-cache.php

runtime-cache-clear: .logo ## Clear the current UID's media cache in the isolated runtime volume.
	$(COMPOSE_BIN) run --rm --volume /media --entrypoint php runtime /app/scripts/clear-cache.php --media-only

cache-permissions-check: .logo ## Verify synthetic cache isolation between two real Unix UIDs.
	$(COMPOSE_BIN) run --rm --user 0:0 buildbox php scripts/check-cache-permissions.php

version: .logo ## Create a new version release.
	@bash scripts/create-version
