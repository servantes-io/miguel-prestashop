#!/usr/bin/env bash

tmpdir=$(mktemp -d)
ignore=".git .github .idea .vscode .gitignore .php-cs-fixer.dist.php .php-cs-fixer.cache composer.* tests run doc vendor vendor2 docker-compose.yml docker-compose.test.yml Makefile *.zip"
result="$(pwd)/miguel.zip"

rm -rf "$result"

mkdir -p $tmpdir/miguel
rsync -a --exclude=.git --exclude=run --exclude=vendor --exclude=vendor2 "." "${tmpdir}/miguel/"

pushd $tmpdir > /dev/null
    for i in $ignore; do
        rm -rf miguel/$i
    done

    # Remove blank lines after opening <?php tags (PrestaShop coding standard)
    find miguel -name "*.php" -type f -exec sh -c '
        if [ -s "$1" ] && head -n2 "$1" | grep -q "^<?php$" && head -n2 "$1" | tail -n1 | grep -q "^$"; then
            sed -i "" "2d" "$1"
        fi
    ' _ {} \;

    find miguel -name ".DS_Store" -depth -exec rm {} \;

    zip "${result}" -r miguel
popd > /dev/null

rm -rf "$tmpdir"
