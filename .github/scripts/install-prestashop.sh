#!/usr/bin/env bash

mkdir -p vendor2
if [ ! -d vendor2/PrestaShop ]; then
    pushd vendor2 > /dev/null
        # download prestashop
        git clone --depth 1 --branch $PRESTASHOP_VERSION https://github.com/PrestaShop/PrestaShop

        pushd PrestaShop > /dev/null
            # install prestashop
            composer install --prefer-dist --no-progress --no-ansi --no-interaction

            sed -i "s/mysql -u root/mysql -u root --password=password --port ${MYSQL_PORT}/g" travis-scripts/install-prestashop
			sed -i "s/--db_server=127.0.0.1 --db_name=prestashop/--db_server=127.0.0.1:${MYSQL_PORT} --db_name=prestashop --db_user=root --db_password=password/g" travis-scripts/install-prestashop

            echo "new version of travis-scripts/install-prestashop script:"
            cat travis-scripts/install-prestashop
            echo ''

			bash travis-scripts/install-prestashop
        popd > /dev/null
    popd > /dev/null
fi
