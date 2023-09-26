#!/usr/bin/env bash

set -ex

pushd vendor2/PrestaShop > /dev/null
    # Fixes from https://github.com/retailcrm/prestashop-module/blob/c531f9b1249eef2ffbad6eaecc686cada9a975f7/Makefile#L60
    sed -i 's/throw new Exception/#throw new Exception/g' src/PrestaShopBundle/Install/DatabaseDump.php

    sed -i "s/SymfonyContainer::getInstance()->get('translator')/\\\\Context::getContext()->getTranslator()/g" classes/lang/DataLang.php
    cat classes/lang/DataLang.php | grep -A 3 -B 3 'this->translator = '

    sed -i "s/SymfonyContainer::getInstance()->get('translator')/\\\\Context::getContext()->getTranslator()/g" classes/Language.php
    sed -i "s/\$sfContainer->get('translator')/\\\\Context::getContext()->getTranslator()/g" classes/Language.php
    cat classes/Language.php | grep -A 3 -B 3 'translator = '

    # Clean up needed for StarterTheme tests
    mysql -u root --password=password --port ${MYSQL_PORT} -h 127.0.0.1 -e "
      DROP DATABASE IF EXISTS \`prestashop\`;
      DROP DATABASE IF EXISTS \`test_prestashop\`;
    "

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

    mkdir -p modules/miguel
popd > /dev/null
