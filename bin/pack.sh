#!/usr/bin/env bash

# expected to run from root of this repo

tempdir=$(mktemp -d)
currentdir=$(pwd)
outputFile="$currentdir/$1"

rm -rf "$outputFile"

cp -R . "$tempdir/miguel"

pushd "$tempdir" > /dev/null
    zip -r "$outputFile" . \
      --include "*.php" "*.png" "*.jpg" "*.md" \
      -x "miguel/_2023-02-01 release 101/*"
popd > /dev/null

rm -rf "$tempdir"
