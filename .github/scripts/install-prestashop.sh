#!/usr/bin/env bash

mkdir -p vendor2
if [ ! -d vendor2/PrestaShop ]; then
    pushd vendor2 > /dev/null
        # download prestashop
        git clone --depth 1 --branch $PRESTASHOP_VERSION https://github.com/PrestaShop/PrestaShop

        pushd PrestaShop > /dev/null
            # install deps
            composer install --prefer-dist --no-progress --no-ansi --no-interaction

            # Fixes from https://github.com/retailcrm/prestashop-module/blob/c531f9b1249eef2ffbad6eaecc686cada9a975f7/Makefile#L60
            sed -i 's/throw new Exception/#throw new Exception/g' src/PrestaShopBundle/Install/DatabaseDump.php

            sed -i "s/SymfonyContainer::getInstance()->get('translator')/\\\\Context::getContext()->getTranslator()/g" classes/lang/DataLang.php
	        cat classes/lang/DataLang.php | grep -A 3 -B 3 'this->translator = '

	        sed -i "s/SymfonyContainer::getInstance()->get('translator')/\\\\Context::getContext()->getTranslator()/g" classes/Language.php
	        cat classes/Language.php | grep -A 3 -B 3 'translator = '

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
