#!/bin/bash

set -e

echo "Cleaning Up"
rm -rf ../laravel/

echo "Installing Laravel"
composer create-project --quiet --prefer-dist "laravel/laravel" ../laravel
cd ../laravel/

echo "Adding package from source"
sed -e 's|"type": "project",|&"repositories": [ { "type": "path", "url": "../psalm-plugin-laravel" } ],|' -i '' composer.json
COMPOSER_MEMORY_LIMIT=-1 composer require --dev "psalm/plugin-laravel:*"

echo "Analyzing Laravel"
./vendor/bin/psalm -c ../psalm-plugin-laravel/tests/laravel-test-psalm.xml
