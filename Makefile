COMPOSE_TEST = docker compose -f docker-compose.test.yml

build-zip:
	bash .github/scripts/build-zip.sh

# Run the PHPUnit suite entirely in Docker (no host PHP/MySQL needed).
# Pass extra phpunit args via ARGS, e.g. `make test-docker ARGS="--filter ApiDispatcherTest"`.
test-docker:
	$(COMPOSE_TEST) run --rm phpunit $(ARGS)

# Build (or rebuild) the test image.
test-docker-build:
	$(COMPOSE_TEST) build

# Tear down the test stack and drop the cached PrestaShop install + DB volumes.
test-docker-clean:
	$(COMPOSE_TEST) down -v

# Alias: `make test` runs the Docker suite.
test: test-docker
