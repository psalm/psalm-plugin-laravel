#!/bin/bash

set -e

CURRENT_SCRIPT_PATH="$( cd "$(dirname "$0")" ; pwd -P )"
APP_INSTALLATION_PATH="$(dirname "$CURRENT_SCRIPT_PATH")/../lumen"

echo "Cleaning up previous installation"
rm -rf $APP_INSTALLATION_PATH

echo "Installing Lumen"
# See https://github.com/laravel/lumen/tags for Lumen versions
composer create-project laravel/lumen $APP_INSTALLATION_PATH 9.* --quiet --prefer-dist
cd $APP_INSTALLATION_PATH

echo "Adding package from source"
composer config repositories.0 '{"type": "path", "url": "../psalm-plugin-laravel"}'
COMPOSER_MEMORY_LIMIT=-1 composer require --dev "psalm/plugin-laravel:*" -W

echo "Analyzing Lumen"
./vendor/bin/psalm -c ../psalm-plugin-laravel/tests/lumen-test-psalm.xml

echo -e "\nA sample Lumen application installed at the $APP_INSTALLATION_PATH directory, feel free to remove it."
