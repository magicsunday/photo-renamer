# =============================================================================
# TARGETS
# =============================================================================

#### Docker

.PHONY: docker-build runtime-build runtime-image-check bash

docker-build: .logo ## Builds the Docker image.
	@rm -f $(DI_CACHE)
	$(COMPOSE_BIN) build

runtime-build: .logo ## Build immutable code/vendor/DI and the isolated media image.
	$(COMPOSE_BIN) build runtime

runtime-image-check: .logo ## Builds and verifies the runtime image and native media decoder contract.
	$(COMPOSE_BIN) build buildbox runtime
	bash scripts/check-runtime-image.sh

bash: .logo ## Opens a bash within the buildbox container.
	$(COMPOSE_BUILD) bash


#### Tools

.PHONY: run

run: .logo ## Runs the isolated CLI (usage: MEDIA_DIR=/photos make run CMD="rename:exif /media --dry-run").
	$(COMPOSE_BIN) run --rm runtime $(CMD)
