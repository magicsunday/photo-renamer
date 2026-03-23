# =============================================================================
# Variables
# =============================================================================

METADATA_CACHE = $${CACHE_DIR:-.build/cache}/metadata-cache.json
SIGNAL_CACHE   = $${CACHE_DIR:-.build/cache}/perceptual-signal-cache.json
DI_CACHE       = .build/cache/DependencyContainer.php

# =============================================================================
# TARGETS
# =============================================================================

#### Application

.PHONY: binary binary-init binary-clean cache-clear version

binary: .logo ## Build the self-contained renamer binary.
	@rm -f $(METADATA_CACHE) $(SIGNAL_CACHE) $(DI_CACHE)
	@bash scripts/build

binary-init: .logo ## Initialize SPC build environment (download + compile PHP).
	@bash scripts/init-with-docker

binary-clean: .logo ## Remove SPC build artifacts to free space.
	@rm -rf .build/spc/pkgroot/ .build/spc/downloads/ .build/spc/source/

cache-clear: .logo ## Clear all persistent caches (metadata + perceptual signals + DI container).
	@rm -f $(METADATA_CACHE) $(SIGNAL_CACHE) $(DI_CACHE)
	@echo "Caches cleared (metadata + perceptual signals + DI container)."

version: .logo ## Create a new version release.
	@bash scripts/create-version
