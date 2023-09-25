#!/usr/bin/env bash

set -ex

mkdir -p vendor2
if [ ! -d vendor2/PrestaShop ]; then
    pushd vendor2 > /dev/null
        # download prestashop
        git clone --depth 1 --branch $PRESTASHOP_VERSION https://github.com/PrestaShop/PrestaShop

        pushd PrestaShop > /dev/null
            # install deps
            composer install --prefer-dist --no-progress --no-ansi --no-interaction
        popd
    popd
fi
