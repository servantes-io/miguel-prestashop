#!/usr/bin/env bash

tmpdir=$(mktemp -d)
ignore=".git .github .idea .vscode .gitignore .dockerignore .php-cs-fixer.dist.php .php-cs-fixer.cache composer.* tests run doc docs vendor vendor2 docker Dockerfile.test docker-compose.yml docker-compose.test.yml Makefile .superpowers *.zip"
result="$(pwd)/miguel.zip"

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
