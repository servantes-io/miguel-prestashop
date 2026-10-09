#!/usr/bin/env bash
#
# phpunit_docker.sh — run this module's whole PHPUnit suite in Docker, the
# way `make test-docker` does, on a machine that has Docker but no PHP
# toolchain.
#
# It takes NO arguments, on purpose. The youtrack-triage implement loop runs it
# by name as this repository's test command, admits it only without arguments,
# and before running it requires this file, docker-compose.test.yml,
# Dockerfile.test and docker/test-entrypoint.sh to be byte-identical to
# origin/main — so a branch cannot change what "run the tests" means. It has
# the same name as miguel-woocommerce's wrapper; the loop pins each repo's own
# chain. For a filtered run while developing, use
# `make test-docker ARGS="--filter X"`.
#
# What it does, in order:
#   1. takes a host-wide lock, so two runs never share one test database, and
#      clears whatever a killed earlier run left behind;
#   2. runs the suite through docker/test-entrypoint.sh, which clones and
#      installs PrestaShop into cached volumes on first use (slow, once),
#      resets the test database from the pristine install, places the module
#      and runs PHPUnit;
#   3. brings the compose project down on any exit, including TERM from a
#      runner's timeout, so no MySQL container is left behind. Volumes are
#      kept: they are the PrestaShop install and database caches that make
#      later runs fast.
#
# The compose project name is fixed (not the directory name) so the cache
# volumes are shared by every checkout and worktree, and so `down` can never
# touch a developer's own stack, which runs under the default project name.
set -euo pipefail

if [ "$#" -ne 0 ]; then
    echo "phpunit_docker.sh takes no arguments (see the header for a filtered run)" >&2
    exit 2
fi

cd "$(dirname "$0")/.."

PROJECT="miguel-prestashop-phpunit"
# --env-file /dev/null: compose would otherwise read a .env from the checkout,
# and a branch's .env could change what gets installed into the SHARED cache
# volumes for every later run.
COMPOSE=(docker compose --env-file /dev/null -f docker-compose.test.yml -p "$PROJECT")

# A fixed path, never $TMPDIR: two runs that saw different TMPDIRs would take
# different locks, share one database anyway, and the first run's `down` would
# kill the other mid-suite.
exec 9>"/tmp/${PROJECT}.lock"
flock 9

cleanup() { "${COMPOSE[@]}" down --remove-orphans >/dev/null 2>&1 || true; }
# Clear anything a KILLed earlier run left behind (a SIGKILL skips its trap,
# and its containers keep running) -- otherwise this run would reuse that
# run's database while its orphaned phpunit is still using it.
cleanup
trap cleanup EXIT
trap 'exit 143' TERM INT

echo "==> phpunit"
"${COMPOSE[@]}" run --build --rm phpunit
