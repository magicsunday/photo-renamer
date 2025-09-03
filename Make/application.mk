# =============================================================================
# TARGETS
# =============================================================================

#### Application

.PHONY: build cleanup init init-with-docker version

build: ## Build a new renamer binary
	@bash .build/build

cleanup: ## Removes all sources, downloads and pkgroot to free some space which is not needed after spc was built
	@rm -rf spc/pkgroot/ spc/downloads/ spc/source/

init: ## Initialize the build environment and create necessary files
	@bash .build/init

init-with-docker: ## Initialize the build environment with the help of Docker
	@bash .build/init-with-docker

version: ## Create a new version release and trigger build of new binary
	@bash scripts/create-version
