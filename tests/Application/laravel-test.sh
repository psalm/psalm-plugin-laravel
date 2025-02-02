#!/bin/bash

set -e

# Parse command line arguments
UPDATE_BASELINE=false
while [[ $# -gt 0 ]]; do
    case $1 in
        -u|--update)
            UPDATE_BASELINE=true
            shift
            ;;
        *)
            echo "Unknown option: $1"
            echo "Usage: $0 [-u|--update]"
            echo "  -u, --update    Update Psalm baseline"
            exit 1
            ;;
    esac
done

CURRENT_SCRIPT_PATH="$( cd "$(dirname "$0")" ; pwd -P )"
APP_INSTALLATION_PATH="$(dirname "$(dirname "$CURRENT_SCRIPT_PATH")")/tests-app/laravel-example"

echo "Cleaning up previous installation"
rm -rf $APP_INSTALLATION_PATH

echo "Creating a new Laravel project"
# See https://github.com/laravel/laravel/tags for Laravel versions
composer create-project --quiet --prefer-dist laravel/laravel $APP_INSTALLATION_PATH 11.6 --quiet --prefer-dist
cd $APP_INSTALLATION_PATH

echo "Making different types of classes for Laravel"
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
if [ "$UPDATE_BASELINE" = true ]; then
    ./vendor/bin/psalm -c ../../tests/Application/laravel-test-psalm.xml --set-baseline=../../tests/Application/laravel-test-baseline.xml
    echo -e "\nBaseline file tests/Application/laravel-test-baseline.xml is updated, please check the changes and commit them."
else
    ./vendor/bin/psalm -c ../../tests/Application/laravel-test-psalm.xml --use-baseline=../../tests/Application/laravel-test-baseline.xml
fi

echo -e "\nA sample Laravel application installed at the $APP_INSTALLATION_PATH directory, feel free to remove it."
