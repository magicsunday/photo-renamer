# =============================================================================
# TARGETS
# =============================================================================

#### Docker

.PHONY: docker-build bash

docker-build: .logo ## Builds the Docker image.
	$(COMPOSE_BIN) build

bash: .logo ## Opens a bash within the buildbox container.
	${COMPOSE_BUILD} bash
