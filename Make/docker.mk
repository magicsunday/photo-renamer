# =============================================================================
# TARGETS
# =============================================================================

#### Docker

.PHONY: docker-build bash

docker-build: .logo ## Builds the Docker image.
	$(COMPOSE_BIN) build

bash: .logo ## Opens a bash within the buildbox container.
	${COMPOSE_BUILD} bash


#### Tools

.PHONY: run

run: .logo ## Runs the renamer CLI (usage: make run CMD="rename:exif images --dry-run").
	${COMPOSE_BUILD} php -d memory_limit=-1 src/Renamer.php $(CMD)
