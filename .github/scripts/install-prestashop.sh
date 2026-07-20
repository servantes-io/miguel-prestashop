#!/usr/bin/env bash

function mysed() {
  local regex=$1
  local file=$2

  if [ -f "$file" ]; then
    if [[ "$OSTYPE" == "darwin"* ]]; then
      sed -i '' "$regex" "$file"
    else
      sed -i "$regex" "$file"
    fi
  fi
}

set -ex

pushd vendor2/PrestaShop > /dev/null
    # Fixes from https://github.com/retailcrm/prestashop-module/blob/c531f9b1249eef2ffbad6eaecc686cada9a975f7/Makefile#L60
    mysed 's/throw new Exception/#throw new Exception/g' src/PrestaShopBundle/Install/DatabaseDump.php

    mysed "s/SymfonyContainer::getInstance()->get('translator')/\\\\Context::getContext()->getTranslator()/g" classes/lang/DataLang.php
    cat classes/lang/DataLang.php | grep -A 3 -B 3 'this->translator = '

    mysed "s/SymfonyContainer::getInstance()->get('translator')/\\\\Context::getContext()->getTranslator()/g" classes/Language.php
    mysed "s/\$sfContainer->get('translator')/\\\\Context::getContext()->getTranslator()/g" classes/Language.php
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
    pushd install-dev > /dev/null
      php index_cli.php --language=en --country=fr --domain=localhost --db_server=127.0.0.1:${MYSQL_PORT} --db_name=prestashop --db_user=root --db_password=password --db_create=1 --name=prestashop.unit.test --email=demo@prestashop.com --password=prestashop_demo
      if test ! $? -eq 0; then
          echo "Installed failed, displaying errors from logs:"
          echo
          cat var/logs/* | grep -v error
          exit 1
      fi
    popd > /dev/null

    # create test db
    composer run-script create-test-db

    # Warm the Symfony DI container before any module is installed.
    #
    # On a cache hit the "Download PrestaShop" step is skipped, so the container
    # is never pre-warmed by the clone/composer/asset build. The first *cold*
    # container build then happens during `prestashop:module install`, and that
    # cold build omits module service definitions -- so ps_distributionapiclient's
    # actionBeforeInstallModule hook (which fires on every module install) dies
    # with: You have requested a non-existent service
    # "distributionapiclient.distribution_api" (PrestaShop 9.0).
    #
    # A prime build followed by a rebuild yields a complete container that
    # includes every active module's services. Fresh (cache-miss) runs already
    # get this for free via the download/build step.
    php bin/console cache:clear
    php bin/console cache:clear

    mkdir -p modules/miguel
popd > /dev/null
