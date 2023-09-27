#!/usr/bin/env bash

tmpdir=$(mktemp -d)
ignore=".git .github .idea .vscode .gitignore .php-cs-fixer.dist.php .php-cs-fixer.cache composer.* tests run doc vendor vendor2 docker-compose.yml Makefile *.zip"
result="$(PWD)/miguel.zip"

rm -rf "$result"

mkdir -p $tmpdir/miguel
rsync -a --exclude=.git --exclude=run --exclude=vendor --exclude=vendor2 "." "${tmpdir}/miguel/"

pushd $tmpdir > /dev/null
    for i in $ignore; do
        rm -rf miguel/$i
    done

    find miguel -name ".DS_Store" -depth -exec rm {} \;

    zip "${result}" -r miguel
popd > /dev/null

rm -rf "$tmpdir"
