# =============================================================================
# TARGETS
# =============================================================================

#### Application

.PHONY: binary binary-clean version

binary: .logo ## Build the self-contained renamer binary.
	@bash scripts/init-with-docker
	@bash scripts/build

binary-clean: .logo ## Remove SPC build artifacts to free space.
	@rm -rf .build/spc/pkgroot/ .build/spc/downloads/ .build/spc/source/

version: .logo ## Create a new version release.
	@bash scripts/create-version
