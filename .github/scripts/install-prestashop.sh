#!/usr/bin/env bash

mkdir -p vendor2
if [ ! -d vendor2/PrestaShop ]; then
    pushd vendor2 > /dev/null
        # download prestashop
        git clone --depth 1 --branch $PRESTASHOP_VERSION https://github.com/PrestaShop/PrestaShop

        pushd PrestaShop > /dev/null
            # install deps
            composer install --prefer-dist --no-progress --no-ansi --no-interaction

            # Clean up needed for StarterTheme tests
            mysql -u root --password=password --port ${MYSQL_PORT} -e "DROP DATABASE IF EXISTS \`prestashop\`;"

            # Remove cache
            rm -rf var/cache/*
            # Remove logs
            rm -rf var/logs/*

            echo "* Installing PrestaShop, this may take a while ...";
            php install-dev/index_cli.php --language=en --country=fr --domain=localhost --db_server=127.0.0.1:${MYSQL_PORT} --db_name=prestashop --db_user=root --db_password=password --db_create=1 --name=prestashop.unit.test --email=demo@prestashop.com --password=prestashop_demo
            if test ! $? -eq 0; then
                echo "Installed failed, displaying errors from logs:"
                echo
                cat var/logs/* | grep -v error
                exit 1
            fi

            # create test db
            composer run-script create-test-db
        popd > /dev/null
    popd > /dev/null
fi
