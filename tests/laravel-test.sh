#!/bin/bash

set -e

echo "Cleaning Up"
rm -rf ./laravel/

echo "Installing Laravel"
composer create-project laravel/laravel ./laravel 8.6.* --quiet --prefer-dist
cd ./laravel/

echo "Adding package from source"
composer config repositories.0 '{"type": "path", "url": "../"}'
composer config minimum-stability 'dev'
COMPOSER_MEMORY_LIMIT=-1 composer require --dev "psalm/plugin-laravel:*" --update-with-all-dependencies

echo "Preparing Laravel"
./artisan make:cast ExampleCast
./artisan make:channel ExampleChannel
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
./artisan make:seeder ExampleSeeder

cp ../tests/laravel-test-psalm.xml ./psalm.xml
cp ../tests/laravel-test-psalm-baseline.xml ./psalm-baseline.xml

echo "Laravel App Analyse"
./vendor/bin/psalm -c
