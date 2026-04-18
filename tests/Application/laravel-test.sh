#!/usr/bin/env bash

# Laravel Test Environment Setup Script
# This script sets up a fresh Laravel installation and runs Psalm analysis
# examples:
#   bash tests/Application/laravel-test.sh
#   LARAVEL_INSTALLER_VERSION=12.12.2 bash tests/Application/laravel-test.sh

# Exit on error. Append "|| true" if you expect an error.
set -e
# Exit on error in any nested commands
set -o pipefail
# Catch the error in case a variable is not set
set -u

# See https://github.com/laravel/laravel/tags for Laravel versions
LARAVEL_INSTALLER_VERSION="${LARAVEL_INSTALLER_VERSION:-12.12.2}"

# Terminal colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Default values
UPDATE_BASELINE=false
VERBOSE=false
REMOVE=false
PSALM_PASSED=false

# Function to display script usage
show_help() {
    cat << EOF
Usage: $(basename "$0") [options]

Sets up a fresh Laravel installation and runs Psalm analysis.

Options:
    -h, --help                          Show this help message
    -u, --update                        Update Psalm baseline
    -v, --verbose                       Enable verbose output
    -r, --remove   Remove Laravel project directory after execution

Environment variables:
    LARAVEL_INSTALLER_VERSION    Laravel version to install (default: 12.12.2)
    COMPOSER_MEMORY_LIMIT        Memory limit for Composer (default: -1)
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
        if [ "$PSALM_PASSED" = true ] || [ "$REMOVE" = true ]; then
            rm -rf "$APP_INSTALLATION_PATH"
            debug "Test Laravel app has been removed"
        else
            info "Test Laravel application preserved at $APP_INSTALLATION_PATH"
            info "Run with --remove flag or delete manually: rm -rf $APP_INSTALLATION_PATH"
        fi
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

# Get absolute path of script directory
CURRENT_SCRIPT_PATH="$( cd "$(dirname "$0")" ; pwd -P )"
PROJECT_ROOT="$(dirname "$(dirname "$CURRENT_SCRIPT_PATH")")"
APP_INSTALLATION_PATH="$PROJECT_ROOT/tests-app/laravel-example"

trap cleanup EXIT

if [ -d "$APP_INSTALLATION_PATH" ]; then
    info "Removing previous installation..."
    rm -rf "$APP_INSTALLATION_PATH"
    info "Removed."
fi

RELATIVE_PATH="${APP_INSTALLATION_PATH#"$PROJECT_ROOT"/}"
info "Creating a new Laravel project using installer v${LARAVEL_INSTALLER_VERSION} at ${RELATIVE_PATH} ..."
info "Tip: set LARAVEL_INSTALLER_VERSION to test against a different Laravel version"
# --no-security-blocking: laravel/laravel's pinned phpunit/phpunit range can become
# fully covered by a fresh advisory, which would otherwise make `composer create-project`
# fail to resolve. Advisories are still reported. This is a test-only scaffold.
composer create-project --quiet --prefer-dist --no-security-blocking laravel/laravel "$APP_INSTALLATION_PATH" "$LARAVEL_INSTALLER_VERSION"
cd "$APP_INSTALLATION_PATH"

info "Making different types of classes for Laravel to analyze them using Psalm"
# Invoke every generator inside a single bootstrapped Laravel process — spawning
# ~30 separate `./artisan` invocations is ~16× slower (~10s vs ~0.6s locally).
php -r '
require __DIR__."/vendor/autoload.php";
$app = require __DIR__."/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$output = new Symfony\Component\Console\Output\ConsoleOutput();
$cmds = [
    ["make:cast", "ExampleCast"],
    ["make:channel", "ExampleChannel"],
    ["make:component", "ExampleComponent"],
    ["make:command", "ExampleCommand"],
    ["make:controller", "ExampleController"],
    ["make:event", "ExampleEvent"],
    ["make:exception", "ExampleException"],
    ["make:factory", "ExampleFactory"],
    ["make:job", "ExampleJob"],
    ["make:listener", "ExampleListener"],
    ["make:mail", "ExampleMail"],
    ["make:middleware", "ExampleMiddleware"],
    ["make:model", "Example"],
    ["make:notification", "ExampleNotification"],
    ["make:observer", "ExampleObserver"],
    ["make:policy", "ExamplePolicy"],
    ["make:provider", "ExampleProvider"],
    ["make:request", "ExampleRequest"],
    ["make:resource", "ExampleResource"],
    ["make:rule", "ExampleRule"],
    ["make:scope", "ExampleScope"],
    ["make:seeder", "ExampleSeeder"],
    ["make:class", "Services/ExampleServiceClass"],
    ["make:interface", "Services/ExampleServiceInterface"],
    ["make:enum", "Enums/UserRole"],
    ["make:job-middleware", "ExampleJobMiddleware"],
    ["make:test", "NewExampleTest"],
    ["make:trait", "Traits/ExampleTrait"],
    ["make:view", "example-view"],
];
foreach ($cmds as [$cmd, $name]) {
    $exit = Illuminate\Support\Facades\Artisan::call($cmd, ["name" => $name], $output);
    if ($exit !== 0) {
        fwrite(STDERR, "artisan {$cmd} {$name} failed (exit {$exit})\n");
        exit($exit);
    }
}
' | awk 'NF'

info "Adding package from source"
composer config repositories.0 '{"type": "path", "url": "../../"}'
composer config minimum-stability 'dev'
COMPOSER_MEMORY_LIMIT=-1 composer require --dev "psalm/plugin-laravel:*" --update-with-all-dependencies

info "Analyzing Laravel"
PSALM_CONFIG="../../tests/Application/laravel-test-psalm.xml"
PSALM_BASELINE="../../tests/Application/laravel-test-psalm-baseline.xml"

if [ "$UPDATE_BASELINE" = true ]; then
    info "Updating Psalm baseline"
    ./vendor/bin/psalm --config="$PSALM_CONFIG" --set-baseline="$PSALM_BASELINE"
    info "Baseline file $PSALM_BASELINE is updated, please check the changes and commit them."
else
    info "Running Psalm analysis"
    # set -e ensures script exits on failure, so cleanup below only runs on success
    ./vendor/bin/psalm --config="$PSALM_CONFIG" --use-baseline="$PSALM_BASELINE" --show-info=true --stats
fi

echo

# Psalm succeeded — mark for cleanup by the EXIT trap
PSALM_PASSED=true
