# =============================================================================
# TARGETS
# =============================================================================

#### Docker

.PHONY: docker-build runtime-image-check bash

docker-build: .logo ## Builds the Docker image.
	@rm -f $(DI_CACHE)
	$(COMPOSE_BIN) build

runtime-image-check: .logo ## Builds and verifies the runtime image and native media decoder contract.
	$(COMPOSE_BIN) build buildbox
	bash scripts/check-runtime-image

bash: .logo ## Opens a bash within the buildbox container.
	$(COMPOSE_BUILD) bash


#### Tools

.PHONY: run

run: .logo ## Runs the renamer CLI (usage: make run CMD="rename:exif images --dry-run").
	$(COMPOSE_BUILD) php -d memory_limit=-1 src/Renamer.php $(CMD)
