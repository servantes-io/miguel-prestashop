#!/usr/bin/env bash
#
# Entrypoint for the containerized PHPUnit run (docker-compose.test.yml).
#
# Runs the module's test suite entirely in Docker (no host PHP/MySQL needed):
#   1. wait for the MySQL service
#   2. ensure a clean PrestaShop checkout in the cache volume (git clone once)
#   3. install PrestaShop against the container DB (once per DB volume)
#   4. create the test DB as a copy of the fresh install
#   5. place the module under PrestaShop/modules/miguel (like the CI zip step)
#   6. run PHPUnit from PrestaShop's context (PrestaShop's phpunit binary)
#
# Args passed to `docker compose run --rm phpunit <args>` are forwarded to phpunit,
# e.g. `make test-docker ARGS="--filter ApiDispatcherTest"`.
set -euo pipefail

DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-password}"
DB_NAME="${DB_NAME:-prestashop}"
TEST_DB_NAME="test_${DB_NAME}"
PRESTASHOP_VERSION="${PRESTASHOP_VERSION:-1.7.8.10}"

PS_DIR="/project/vendor2/PrestaShop"
DB_STRUCTURE="$PS_DIR/install-dev/data/db_structure.sql"
MODULE_DEST="$PS_DIR/modules/miguel"
INSTALL_MARKER="/project/vendor2/.miguel-docker-db-installed"

log() { echo "==> $*"; }

# The Debian MariaDB client defaults to TLS; disable it when the flag exists.
MYSQL_SSL_FLAG=""
if mysql --help 2>&1 | grep -q -- '--skip-ssl'; then
    MYSQL_SSL_FLAG="--skip-ssl"
fi

mysql_cli() {
    mysql $MYSQL_SSL_FLAG -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$@"
}

wait_for_db() {
    log "Waiting for MySQL at ${DB_HOST}:${DB_PORT}..."
    until mysql_cli -e "SELECT 1" >/dev/null 2>&1; do
        sleep 2
    done
    log "MySQL ready."
}

# A valid checkout has the CLI installer and the PrestaShop test bootstrap.
# (The base `shop` table lives in the installer's upgrade SQL, not db_structure.sql,
# so a real post-install check happens in install_db after index_cli runs.)
prestashop_is_valid() {
    [ -f "$PS_DIR/install-dev/index_cli.php" ] &&
        [ -f "$PS_DIR/tests-legacy/bootstrap.php" ]
}

ensure_prestashop() {
    if prestashop_is_valid; then
        log "PrestaShop present in cache volume."
        return 0
    fi
    log "Cloning PrestaShop ${PRESTASHOP_VERSION} (one-time, slow)..."
    rm -rf "$PS_DIR"
    git clone --depth 1 --branch "$PRESTASHOP_VERSION" \
        https://github.com/PrestaShop/PrestaShop "$PS_DIR"
    log "Installing PrestaShop composer dependencies..."
    composer install --working-dir="$PS_DIR" \
        --prefer-dist --no-progress --no-ansi --no-interaction
    prestashop_is_valid || { log "ERROR: cloned PrestaShop is still invalid."; exit 1; }
    log "PrestaShop ready."
}

# Test-DB fixes from .github/scripts/install-prestashop.sh, applied once.
patch_prestashop_source() {
    local dump="$PS_DIR/src/PrestaShopBundle/Install/DatabaseDump.php"
    if [ -f "$dump" ] && ! grep -q '#throw new Exception' "$dump"; then
        sed -i 's/throw new Exception/#throw new Exception/g' "$dump"
    fi
    local datalang="$PS_DIR/classes/lang/DataLang.php"
    [ -f "$datalang" ] && sed -i "s/SymfonyContainer::getInstance()->get('translator')/\\\\Context::getContext()->getTranslator()/g" "$datalang"
    local language="$PS_DIR/classes/Language.php"
    if [ -f "$language" ]; then
        sed -i "s/SymfonyContainer::getInstance()->get('translator')/\\\\Context::getContext()->getTranslator()/g" "$language"
        sed -i "s/\$sfContainer->get('translator')/\\\\Context::getContext()->getTranslator()/g" "$language"
    fi
}

# Install the pristine `prestashop` DB once (cached via marker across runs).
# The tests never touch this DB directly — they run against test_prestashop,
# which is a fresh copy of it (see refresh_test_db). This keeps prestashop clean
# as the reset source.
install_base() {
    if [ -f "$INSTALL_MARKER" ]; then
        log "PrestaShop base already installed (marker present); skipping install."
        return 0
    fi

    patch_prestashop_source

    log "Installing PrestaShop into ${DB_HOST}/${DB_NAME} (first run, may take a few minutes)..."
    mysql_cli -e "DROP DATABASE IF EXISTS \`${DB_NAME}\`;"
    rm -f "$PS_DIR/config/settings.inc.php" "$PS_DIR/app/config/parameters.php" "$PS_DIR/app/config/parameters.yml"
    rm -rf "$PS_DIR"/var/cache/* "$PS_DIR"/var/logs/* 2>/dev/null || true

    (
        cd "$PS_DIR/install-dev"
        php index_cli.php \
            --language=en --country=fr --domain=localhost \
            --db_server="${DB_HOST}:${DB_PORT}" --db_name="${DB_NAME}" \
            --db_user="${DB_USER}" --db_password="${DB_PASS}" --db_create=1 \
            --name=prestashop.unit.test --email=demo@prestashop.com --password=prestashop_demo
    )

    if ! mysql_cli -N -e "SELECT 1 FROM information_schema.tables WHERE table_schema='${DB_NAME}' AND table_name='ps_shop' LIMIT 1;" | grep -q 1; then
        log "ERROR: install incomplete — ps_shop table missing."
        exit 1
    fi

    touch "$INSTALL_MARKER"
    log "PrestaShop base installed."
}

# Reset the test database from the pristine install before every run, so each
# `make test-docker` starts from a clean, isolated state (the module's tests do
# not restore the DB themselves).
refresh_test_db() {
    log "Resetting ${TEST_DB_NAME} from a clean ${DB_NAME}..."
    mysql_cli -e "DROP DATABASE IF EXISTS \`${TEST_DB_NAME}\`; CREATE DATABASE \`${TEST_DB_NAME}\` CHARACTER SET utf8mb4;"
    mysqldump $MYSQL_SSL_FLAG -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" \
        --no-tablespaces --skip-triggers "${DB_NAME}" | mysql_cli "${TEST_DB_NAME}"
}

place_module() {
    log "Placing module under PrestaShop/modules/miguel..."
    mkdir -p "$MODULE_DEST"
    rsync -a --delete \
        --exclude '.git' --exclude 'vendor2' --exclude 'vendor' \
        --exclude 'run' --exclude 'node_modules' --exclude '*.zip' \
        /project/ "$MODULE_DEST/"
}

run_phpunit() {
    log "Running PHPUnit..."
    cd "$PS_DIR"
    exec composer exec phpunit -- -c modules/miguel/tests/Unit/phpunit.xml "$@"
}

wait_for_db
ensure_prestashop
install_base
refresh_test_db
place_module
run_phpunit "$@"
