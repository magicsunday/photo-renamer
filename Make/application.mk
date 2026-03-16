# =============================================================================
# TARGETS
# =============================================================================

#### Application

.PHONY: build cleanup init init-with-docker version

build: .logo ## Build a new renamer binary.
	@bash .build/build

cleanup: .logo ## Removes build artifacts to free space.
	@rm -rf spc/pkgroot/ spc/downloads/ spc/source/

init: .logo ## Initialize the SPC build environment.
	@bash .build/init

init-with-docker: .logo ## Initialize the SPC build environment with Docker.
	@bash .build/init-with-docker

version: .logo ## Create a new version release.
	@bash scripts/create-version
