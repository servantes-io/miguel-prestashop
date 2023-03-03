#!/usr/bin/env bash

# expected to run from root of this repo

tempdir=$(mktemp -d)
currentdir=$(pwd)
outputFile="$currentdir/$1"

rm -rf "$outputFile"

cp -R . "$tempdir/miguel"

pushd "$tempdir" > /dev/null
    zip -r "$outputFile" . --include "*.php" "*.png" "*.jpg" "*.md" "*.tpl" "*.js" "*.css"
popd > /dev/null

rm -rf "$tempdir"
