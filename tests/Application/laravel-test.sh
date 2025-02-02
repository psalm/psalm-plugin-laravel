#!/bin/bash

set -e

CURRENT_SCRIPT_PATH="$( cd "$(dirname "$0")" ; pwd -P )"
APP_INSTALLATION_PATH="$(dirname "$(dirname "$CURRENT_SCRIPT_PATH")")/tests-app/laravel-example"

echo "Cleaning up previous installation"
rm -rf $APP_INSTALLATION_PATH

echo "Installing Laravel"
# See https://github.com/laravel/laravel/tags for Laravel versions
composer create-project --quiet --prefer-dist laravel/laravel $APP_INSTALLATION_PATH 11.6 --quiet --prefer-dist
cd $APP_INSTALLATION_PATH

echo "Preparing Laravel"
./artisan make:cast ExampleCast
./artisan make:channel ExampleChannel
./artisan make:component ExampleComponent
./artisan make:command ExampleCommand
./artisan make:controller ExampleController
./artisan make:event ExampleEvent
./artisan make:exception ExampleException
./artisan make:factory ExampleFactory
./artisan make:job ExampleJob
./artisan make:listener ExampleListener
./artisan make:mail ExampleMail
./artisan make:middleware ExampleMiddleware
./artisan make:model Example
./artisan make:notification ExampleNotification
./artisan make:observer ExampleObserver
./artisan make:policy ExamplePolicy
./artisan make:provider ExampleProvider
./artisan make:request ExampleRequest
./artisan make:resource ExampleResource
./artisan make:rule ExampleRule
./artisan make:scope ExampleScope
./artisan make:seeder ExampleSeeder

echo "Adding package from source"
composer config repositories.0 '{"type": "path", "url": "../../"}'
composer config minimum-stability 'dev'
COMPOSER_MEMORY_LIMIT=-1 composer require --dev "psalm/plugin-laravel:*" --update-with-all-dependencies

echo "Analyzing Laravel"
./vendor/bin/psalm -c ../../tests/Application/laravel-test-psalm.xml --use-baseline=../../tests/Application/laravel-test-baseline.xml

echo -e "\nA sample Laravel application installed at the $APP_INSTALLATION_PATH directory, feel free to remove it."
