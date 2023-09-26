#!/usr/bin/env bash

tmpdir=$(mktemp -d)
ignore=".git .github .gitignore .php-cs-fixer.dist.php .php-cs-fixer.cache composer.* tests run doc vendor docker-compose.yml Makefile *.zip"
result="$(PWD)/miguel.zip"

rm -rf "$result"

mkdir -p $tmpdir/miguel
rsync -a --exclude=.git --exclude=run --exclude=vendor "." "${tmpdir}/miguel/"

pushd $tmpdir > /dev/null
    for i in $ignore; do
        rm -rf miguel/$i
    done

    zip "${result}" -r miguel
popd > /dev/null

rm -rf "$tmpdir"
