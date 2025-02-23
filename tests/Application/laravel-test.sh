#!/usr/bin/env bash

# Laravel Test Environment Setup Script
# This script sets up a fresh Laravel installation and runs Psalm analysis

# Exit on error. Append "|| true" if you expect an error.
set -e
# Exit on error in any nested commands
set -o pipefail
# Catch the error in case a variable is not set
set -u

# See https://github.com/laravel/laravel/tags for Laravel versions
LARAVEL_INSTALLER_VERSION=11.6

# Terminal colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Default values
UPDATE_BASELINE=false
VERBOSE=false
REMOVE=false

# Function to display script usage
show_help() {
    cat << EOF
Usage: $(basename "$0") [options]

Sets up a fresh Laravel installation and runs Psalm analysis.

Options:
    -h, --help      Show this help message
    -u, --update    Update Psalm baseline
    -v, --verbose   Enable verbose output
    -r, --remove   Remove Laravel project directory after execution

Environment variables:
    COMPOSER_MEMORY_LIMIT    Memory limit for Composer (default: -1)
EOF
}

# Function to display error messages
error() {
    echo -e "${RED}Error: $1${NC}" >&2
    exit 1
}

# Function to display info messages
info() {
    echo -e "${GREEN}$1${NC}"
}

# Function to display debug messages
debug() {
    if [ "$VERBOSE" = true ]; then
        echo -e "${YELLOW}Debug: $1${NC}"
    fi
}

# Cleanup function
cleanup() {
    if [ -d "$APP_INSTALLATION_PATH" ]; then
        info "Removing the installation directory..."
        rm -rf "$APP_INSTALLATION_PATH"
    fi
}

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        -h|--help)
            show_help
            exit 0
            ;;
        -u|--update)
            UPDATE_BASELINE=true
            shift
            ;;
        -r|--remove)
            REMOVE=true
            shift
            ;;
        -v|--verbose)
            VERBOSE=true
            shift
            ;;
        *)
            error "Unknown option: $1\nUse --help for usage information."
            ;;
    esac
done

if [ "$REMOVE" = true ]; then
    # Set up trap to clean up on script exit
    trap cleanup EXIT
fi

# Get absolute path of script directory
CURRENT_SCRIPT_PATH="$( cd "$(dirname "$0")" ; pwd -P )"
APP_INSTALLATION_PATH="$(dirname "$(dirname "$CURRENT_SCRIPT_PATH")")/tests-app/laravel-example"

if [ -d "$APP_INSTALLATION_PATH" ]; then
    info "Removing previous installation"
    rm -rf "$APP_INSTALLATION_PATH"
fi

info "Creating a new Laravel project (installer v${LARAVEL_INSTALLER_VERSION})"
composer create-project --quiet --prefer-dist laravel/laravel "$APP_INSTALLATION_PATH" $LARAVEL_INSTALLER_VERSION
cd "$APP_INSTALLATION_PATH"

info "Making different types of classes for Laravel"
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
./artisan make:class Services/ExampleServiceClass
./artisan make:interface Services/ExampleServiceInterface
#./artisan make:migration
./artisan make:enum Enums/UserRole
./artisan make:job-middleware ExampleJobMiddleware
./artisan make:test NewExampleTest
./artisan make:trait Traits/ExampleTrait
./artisan make:view example-view

info "Adding package from source"
composer config repositories.0 '{"type": "path", "url": "../../"}'
composer config minimum-stability 'dev'
COMPOSER_MEMORY_LIMIT=-1 composer require --dev "psalm/plugin-laravel:*" --update-with-all-dependencies

info "Analyzing Laravel"
PSALM_CONFIG="../../tests/Application/laravel-test-psalm.xml"
PSALM_BASELINE="../../tests/Application/laravel-test-baseline.xml"

if [ "$UPDATE_BASELINE" = true ]; then
    info "Updating Psalm baseline"
    ./vendor/bin/psalm --config="$PSALM_CONFIG" --set-baseline="$PSALM_BASELINE"
    info "Baseline file $PSALM_BASELINE is updated, please check the changes and commit them."
else
    info "Running Psalm analysis"
    ./vendor/bin/psalm --config="$PSALM_CONFIG" --use-baseline="$PSALM_BASELINE"
fi

echo

if [ "$REMOVE" = false ]; then
    info "A sample Laravel application installed at the $APP_INSTALLATION_PATH directory, feel free to remove it."
fi
