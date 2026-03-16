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

run: .logo ## Runs the renamer CLI (usage: make run CMD="exif:date images --dry-run").
	${COMPOSE_BUILD} php src/Renamer.php $(CMD)
