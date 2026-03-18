# =============================================================================
# TARGETS
# =============================================================================

#### Application

.PHONY: binary binary-init binary-clean cache-clear version

binary: .logo ## Build the self-contained renamer binary.
	@bash scripts/build

binary-init: .logo ## Initialize SPC build environment (download + compile PHP).
	@bash scripts/init-with-docker

binary-clean: .logo ## Remove SPC build artifacts to free space.
	@rm -rf .build/spc/pkgroot/ .build/spc/downloads/ .build/spc/source/

cache-clear: .logo ## Clear the persistent metadata cache.
	@rm -f $${CACHE_DIR:-.build/cache}/metadata-cache.php
	@echo "Metadata cache cleared ($${CACHE_DIR:-.build/cache}/metadata-cache.php)."

version: .logo ## Create a new version release.
	@bash scripts/create-version
