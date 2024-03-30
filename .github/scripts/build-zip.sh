#!/usr/bin/env bash

set -e

# Function to remove space after the first PHPDoc comment
remove_space_after_phpdoc() {
    file="$1"
    echo "Removing space after PHPDoc comment in $file"

    # Check if the file exists
    if [ ! -f "$file" ]; then
        echo "File $file does not exist."
        exit 1
    fi

    # Find the first PHPdoc and remove spaces after it
    perl -CSD -i -pe 'BEGIN{undef $/;} s/\*\/\n\n/*\/\n/smg;' "$file"
}

tmpdir=$(mktemp -d)
ignore=".git .github .idea .vscode .gitignore .php-cs-fixer.dist.php .php-cs-fixer.cache composer.lock assets tests run doc vendor2 docker-compose.yml docker-compose.test.yml Makefile *.zip"
result="$(pwd)/miguel.zip"

rm -rf "$result"

mkdir -p $tmpdir/miguel
rsync -a --exclude=.git --exclude=run --exclude=vendor --exclude=vendor2 "." "${tmpdir}/miguel/"

pushd $tmpdir > /dev/null
    if [[ -f "miguel/composer.json" ]]; then
        pushd miguel > /dev/null
            composer install

            composer exec autoindex
            cp src/index.php .

            composer exec header-stamp -- --license=assets/license-header.txt --exclude=.github,node_modules,vendor,tests,_dev,run,composer.json

            for file in $(find . -type f -name "*.php" ! -path "./vendor/*"); do
                remove_space_after_phpdoc "$file"
            done
            cat index.php

            composer exec php-cs-fixer fix -- --using-cache=no

            rm -rf vendor/

            composer install --no-dev
        popd > /dev/null
    fi

    for i in $ignore; do
        rm -rf miguel/$i
    done

    find miguel -name ".DS_Store" -depth -exec rm {} \;

    zip "${result}" -r miguel
popd > /dev/null

rm -rf "$tmpdir"
