#!/bin/bash

set -e

echo "Cleaning Up"
rm -rf ../laravel/

echo "Installing Laravel"
composer create-project laravel/laravel ../laravel 8.6.* --quiet --prefer-dist
cd ../laravel/

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

echo "Adding package from source"
sed -e 's|"type": "project",|&"repositories": [ { "type": "path", "url": "../psalm-plugin-laravel" } ],|' -i composer.json
COMPOSER_MEMORY_LIMIT=-1 composer require --dev "psalm/plugin-laravel:*" -W

echo "Analyzing Laravel"
./vendor/bin/psalm -c ../psalm-plugin-laravel/tests/laravel-test-psalm.xml
