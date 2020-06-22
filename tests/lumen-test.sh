#!/bin/bash

set -e

echo "Cleaning Up"
rm -rf ../lumen/

echo "Installing Lumen"
composer create-project --quiet --prefer-dist "laravel/lumen" ../lumen
cd ../lumen/

echo "Adding package from source"
sed -e 's|"type": "project",|&"repositories": [ { "type": "path", "url": "../psalm-plugin-laravel" } ],|' -i composer.json
COMPOSER_MEMORY_LIMIT=-1 composer require --dev "psalm/plugin-laravel:*"

echo "Analyzing Lumen"
./vendor/bin/psalm -c ../psalm-plugin-laravel/tests/lumen-test-psalm.xml
