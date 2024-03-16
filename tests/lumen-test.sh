#!/bin/bash

set -e

echo "Cleaning Up"
rm -rf ../lumen/

echo "Installing Lumen"
composer create-project laravel/lumen ../lumen 8.* --quiet --prefer-dist
cd ../lumen/

echo "Adding package from source"
composer config repositories.0 '{"type": "path", "url": "../../"}'
composer config minimum-stability 'dev'
COMPOSER_MEMORY_LIMIT=-1 composer require --dev "psalm/plugin-laravel:*" --update-with-all-dependencies

echo "Analyzing Lumen"
./vendor/bin/psalm -c ../psalm-plugin-laravel/tests/lumen-test-psalm.xml
