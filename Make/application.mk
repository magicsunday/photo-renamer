# =============================================================================
# TARGETS
# =============================================================================

#### Application

.PHONY: binary binary-docker binary-clean version

binary: .logo ## Build the self-contained renamer binary.
	@bash scripts/build

binary-docker: .logo ## Build the binary using Docker (no local toolchain needed).
	@bash scripts/init-with-docker
	@bash scripts/build

binary-clean: .logo ## Remove SPC build artifacts to free space.
	@rm -rf .build/spc/pkgroot/ .build/spc/downloads/ .build/spc/source/

version: .logo ## Create a new version release.
	@bash scripts/create-version
