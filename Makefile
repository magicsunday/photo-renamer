.PHONY: *

help:
	@echo -e "Photo Renamer CLI Tool\n\nUsage: make [target]\n"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-30s\033[0m %s\n", $$1, $$2}'

init: ## Initialize the build environment and create necessary files
	@bash .build/init

init-with-docker: ## Initialize the build environment with the help of Docker
	@bash .build/init-with-docker

build: ## Build a new renamer binary
	@bash .build/build

version: ## Create a new version release and trigger build of new binary
	@bash scripts/create-version

cleanup: ## Removes all sources, downloads and pkgroot to free some space which is not needed after spc was built
	@rm -rf spc/pkgroot/ spc/downloads/ spc/source/
