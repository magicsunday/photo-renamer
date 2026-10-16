# =============================================================================
# Variables
# =============================================================================

DI_CACHE       = .build/cache/DependencyContainer.php

# =============================================================================
# TARGETS
# =============================================================================

#### Application

.PHONY: binary binary-check binary-init binary-clean cache-clear runtime-cache-clear cache-prune runtime-cache-prune cache-permissions-check comparison-benchmark visual-comparison-benchmark metadata-cache-benchmark mixed-collection-benchmark version

visual-comparison-benchmark: ## Evaluate synthetic image-classification candidates offline; emit JSON.
	bash scripts/benchmark-visual-classification.sh $(or $(PROFILE),comparison)

comparison-benchmark: .logo ## Benchmark 1,000 synthetic video candidates under finite Docker resource limits.
	bash scripts/benchmark-video-comparisons.sh

metadata-cache-benchmark: .logo ## Profile a large synthetic metadata snapshot under finite Docker limits.
	bash scripts/benchmark-metadata-cache.sh $(or $(COUNT),100000)

mixed-collection-benchmark: .logo ## Profile synthetic JPG/HEIC+MOV (COUNT=70000, MEMORY_MIB=3072).
	bash scripts/benchmark-mixed-collection.sh $(or $(COUNT),70000) $(or $(MEMORY_MIB),3072)

binary: .logo ## Build the self-contained renamer binary.
	$(COMPOSE_BUILD) php scripts/clear-cache.php
	@bash scripts/build-with-docker.sh

binary-check: .logo ## Test all native CLI commands using synthetic media in the existing runtime image.
	bash scripts/check-binary-commands.sh

binary-init: .logo ## Initialize SPC build environment (download + compile PHP).
	@bash scripts/init-with-docker

binary-clean: .logo ## Remove SPC build artifacts to free space.
	@rm -rf .build/spc/pkgroot/ .build/spc/downloads/ .build/spc/source/ .build/spc/buildroot/ .build/spc/bootstrap.sha256
	@rm -f .build/bin/php .build/bin/composer .build/bin/spc

cache-clear: .logo ## Clear development/legacy media caches and owned DI container.
	$(COMPOSE_BUILD) php scripts/clear-cache.php

runtime-cache-clear: .logo ## Clear the current UID's media cache in the isolated runtime volume.
	$(COMPOSE_BIN) run --rm --volume /media --entrypoint php runtime /app/scripts/clear-cache.php --media-only

cache-prune: .logo ## Prune missing development cache paths with expected media accessible.
	$(COMPOSE_BUILD) php scripts/prune-cache.php

runtime-cache-prune: .logo ## Prune missing runtime cache paths with MEDIA_DIR mounted read-only.
	MEDIA_READ_ONLY=true $(COMPOSE_BIN) run --rm --entrypoint php runtime /app/scripts/prune-cache.php

cache-permissions-check: .logo ## Verify synthetic cache isolation between two real Unix UIDs.
	$(COMPOSE_BIN) run --rm --user 0:0 buildbox php scripts/check-cache-permissions.php

version: .logo ## Create a new version release.
	@bash scripts/create-version
