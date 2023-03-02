#!/bin/bash

set -e

CURRENT_SCRIPT_PATH="$( cd "$(dirname "$0")" ; pwd -P )"
APP_INSTALLATION_PATH="$(dirname "$(dirname "$CURRENT_SCRIPT_PATH")")/tests-app/lumen-example"

echo "Cleaning up previous installation"
rm -rf $APP_INSTALLATION_PATH

echo "Installing Lumen"
# See https://github.com/laravel/lumen/tags for Lumen versions
composer create-project laravel/lumen $APP_INSTALLATION_PATH 10.0 --quiet --prefer-dist
cd $APP_INSTALLATION_PATH

echo "Adding package from source"
composer config repositories.0 '{"type": "path", "url": "../../"}'
composer config minimum-stability 'dev'
COMPOSER_MEMORY_LIMIT=-1 composer require --dev "psalm/plugin-laravel:*" --update-with-all-dependencies

echo "Analyzing Lumen"
./vendor/bin/psalm -c ../../tests/Application/lumen-test-psalm.xml --use-baseline=../../tests/Application/lumen-test-baseline.xml

echo -e "\nA sample Lumen application installed at the $APP_INSTALLATION_PATH directory, feel free to remove it."
