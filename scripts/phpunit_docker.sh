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
# The versions come from the repository, never from this chain: PHP, MySQL and
# PrestaShop from .test-versions (KEY=value lines, # comments and blank lines,
# nothing else), which is NOT pinned, so a branch that upgrades one changes it
# and its tests follow. A file that is missing, unreadable or not in the
# expected shape ends the run with one line on stderr and exit 2: there is no
# default. A value only fills a tag (php:<v>-cli-bullseye, mysql:<v>) or a
# version number, so a branch can bump a version but never choose another
# image. One line on stderr says what runs, before it starts.
#
# What it does, in order:
#   0. reads and checks .test-versions (above);
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
# volumes are shared by every checkout and worktree (each named by the versions it
# holds, see docker-compose.test.yml), and so `down` can never
# touch a developer's own stack, which runs under the default project name.
set -euo pipefail

if [ "$#" -ne 0 ]; then
    echo "phpunit_docker.sh takes no arguments (see the header for a filtered run)" >&2
    exit 2
fi

cd "$(dirname "$0")/.."

PROJECT="miguel-prestashop-phpunit"
VERSIONS_FILE=".test-versions"

refuse() {
    echo "phpunit_docker.sh: cannot read the $1 version from $VERSIONS_FILE: $2" >&2
    exit 2
}

# Read the versions: strict KEY=value lines, a UTF-8 BOM and CR line ends
# ignored, nothing is ever sourced or evaluated.
[ -r "$VERSIONS_FILE" ] || refuse "test" "no such file"
PHP="" MYSQL="" PS=""
SEEN=" "
while IFS= read -r line || [ -n "$line" ]; do
    line="$(printf '%s' "$line" | tr -d '\r' | sed -e "s/^$(printf '\357\273\277')//" \
        -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
    case "$line" in
        "" | "#"*) continue ;;
        PHP_VERSION=* | MYSQL_VERSION=* | PRESTASHOP_VERSION=*)
            key="${line%%=*}"
            case "$SEEN" in
                *" $key "*) refuse "test" "$key is set twice, second time by '$line'" ;;
            esac
            SEEN="$SEEN$key "
            case "$key" in
                PHP_VERSION) PHP="${line#*=}" ;;
                MYSQL_VERSION) MYSQL="${line#*=}" ;;
                PRESTASHOP_VERSION) PS="${line#*=}" ;;
            esac
            ;;
        *) refuse "test" "expected KEY=value lines with the keys PHP_VERSION, MYSQL_VERSION and PRESTASHOP_VERSION, found '$line'" ;;
    esac
done <"$VERSIONS_FILE"
printf '%s\n' "$PHP" | grep -Eq '^[0-9]+\.[0-9]+$' \
    || refuse "PHP" "expected PHP_VERSION=7.4, found '${PHP:-nothing}'"
printf '%s\n' "$MYSQL" | grep -Eq '^[0-9]+(\.[0-9]+)?$' \
    || refuse "MySQL" "expected MYSQL_VERSION=5.7, found '${MYSQL:-nothing}'"
printf '%s\n' "$PS" | grep -Eq '^[0-9]+(\.[0-9]+){1,3}$' \
    || refuse "PrestaShop" "expected PRESTASHOP_VERSION=1.7.8.10, found '${PS:-nothing}'"
echo "phpunit_docker.sh: using PHP $PHP, MySQL $MYSQL, PrestaShop $PS from $VERSIONS_FILE: docker php:${PHP}-cli-bullseye, mysql:${MYSQL}" >&2
# Exported as the checked values, so a variable the caller's shell happens to
# carry (compose lets the environment win over --env-file) can never differ
# from what the line above said.
export PHP_VERSION="$PHP" MYSQL_VERSION="$MYSQL" PRESTASHOP_VERSION="$PS"

# --env-file .test-versions: the versions, checked above. Naming a file also
# stops compose reading a .env from the checkout, which a branch could
# otherwise use to change what gets installed into the SHARED cache volumes
# for every later run.
COMPOSE=(docker compose --env-file "$VERSIONS_FILE" -f docker-compose.test.yml -p "$PROJECT")

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
