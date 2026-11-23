# The Docker test targets take the lock scripts/phpunit_docker.sh takes (same file),
# so a make run never shares the version-named cache volumes or the test database
# with a script run; a machine without flock (macOS) runs without it.
LOCK_FILE = /tmp/miguel-prestashop-phpunit.lock
LOCK := $(shell command -v flock >/dev/null 2>&1 && { [ -e $(LOCK_FILE) ] || : >$(LOCK_FILE); echo "flock $(LOCK_FILE)"; })
COMPOSE_BASE = docker compose --env-file .test-versions -f docker-compose.test.yml
COMPOSE_TEST = $(LOCK) $(COMPOSE_BASE)

build-zip:
	bash .github/scripts/build-zip.sh

# Run the PHPUnit suite entirely in Docker (no host PHP/MySQL needed).
# Pass extra phpunit args via ARGS, e.g. `make test-docker ARGS="--filter ApiDispatcherTest"`.
# The db container is brought down afterwards, under the same lock: left running
# it would hold the shared MySQL data volume and the next run's db would not start.
test-docker:
	$(LOCK) sh -c 'eval "set -- $$ARGS"; $(COMPOSE_BASE) run --rm phpunit "$$@"; rc=$$?; $(COMPOSE_BASE) down --remove-orphans >/dev/null 2>&1; exit $$rc'

# Build (or rebuild) the test image.
test-docker-build:
	$(COMPOSE_TEST) build

# Tear down the test stack and drop the cached PrestaShop install + DB volumes.
test-docker-clean:
	$(COMPOSE_TEST) down -v

# Alias: `make test` runs the Docker suite.
test: test-docker
