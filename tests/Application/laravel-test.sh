#!/usr/bin/env bash

# Laravel Test Environment Setup Script
# This script sets up a fresh Laravel installation and runs Psalm analysis
# examples:
#   bash tests/Application/laravel-test.sh
#   LARAVEL_INSTALLER_VERSION=12.11.2 bash tests/Application/laravel-test.sh

# Exit on error. Append "|| true" if you expect an error.
set -e
# Exit on error in any nested commands
set -o pipefail
# Catch the error in case a variable is not set
set -u

# See https://github.com/laravel/laravel/tags for Laravel versions
LARAVEL_INSTALLER_VERSION="${LARAVEL_INSTALLER_VERSION:-13.1.0}"

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
    LARAVEL_INSTALLER_VERSION    Laravel version to install (default: 12.11.2)
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
composer create-project --quiet --prefer-dist laravel/laravel "$APP_INSTALLATION_PATH" "$LARAVEL_INSTALLER_VERSION"
cd "$APP_INSTALLATION_PATH"

info "Making different types of classes for Laravel to analyze them using Psalm"
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
PSALM_BASELINE="../../tests/Application/laravel-test-psalm-baseline.xml"

if [ "$UPDATE_BASELINE" = true ]; then
    info "Updating Psalm baseline"
    ./vendor/bin/psalm --config="$PSALM_CONFIG" --set-baseline="$PSALM_BASELINE"
    info "Baseline file $PSALM_BASELINE is updated, please check the changes and commit them."
else
    info "Running Psalm analysis"
    # set -e ensures script exits on failure, so cleanup below only runs on success
    ./vendor/bin/psalm --config="$PSALM_CONFIG" --use-baseline="$PSALM_BASELINE"
fi

echo

# laravel/ai requires PHP 8.3+; skip AI taint tests on older PHP
if php -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);'; then
    assert_taint() {
        local class="$1" issue="$2"
        if echo "$TAINT_OUTPUT" | grep -qE "($class.*$issue|$issue.*$class)"; then
            info "OK: $class -> $issue"
        else
            error "$class did not trigger $issue"
        fi
    }

    assert_clean() {
        local class="$1"
        if echo "$TAINT_OUTPUT" | grep -qE "Tainted[A-Za-z]+.*$class|$class.*Tainted[A-Za-z]+"; then
            error "$class triggered a taint issue — false positive"
        fi
        info "OK: $class clean (no false positive)"
    }

    info "PHP >= 8.3: installing laravel/ai and running LLM prompt taint analysis"
    COMPOSER_MEMORY_LIMIT=-1 composer require "laravel/ai:^0.5" --quiet

    info "Creating AI taint analysis test files"
    mkdir -p app/Ai

    cat > app/Ai/UnsafeLlmOutputToSql.php << 'PHPEOF'
<?php declare(strict_types=1);

namespace App\Ai;

use Illuminate\Database\Connection;
use Laravel\Ai\Responses\TextResponse;

final class UnsafeLlmOutputToSql
{
    /** LLM output used in raw SQL — must trigger TaintedSql */
    public function handle(TextResponse $response, Connection $db): void
    {
        $db->raw($response->text);
    }
}
PHPEOF

    cat > app/Ai/UnsafeLlmOutputToHtml.php << 'PHPEOF'
<?php declare(strict_types=1);

namespace App\Ai;

use Laravel\Ai\Responses\TextResponse;

final class UnsafeLlmOutputToHtml
{
    /** LLM output rendered as HTML — must trigger TaintedHtml */
    public function handle(TextResponse $response): void
    {
        echo $response->text;
    }
}
PHPEOF

    cat > app/Ai/UnsafeToolArgsToSql.php << 'PHPEOF'
<?php declare(strict_types=1);

namespace App\Ai;

use Illuminate\Database\Connection;
use Laravel\Ai\Tools\Request;

final class UnsafeToolArgsToSql
{
    /** LLM-controlled tool arguments used in raw SQL — must trigger TaintedSql */
    public function handle(Request $request, Connection $db): void
    {
        $db->raw((string) $request->string('query'));
    }
}
PHPEOF

    cat > app/Ai/UnsafePromptInjection.php << 'PHPEOF'
<?php declare(strict_types=1);

namespace App\Ai;

use Illuminate\Http\Request;
use Laravel\Ai\Promptable;

final class UnsafePromptInjection
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    /** User input sent directly as LLM prompt — must trigger TaintedLlmPrompt */
    public function handle(Request $request): void
    {
        /** @var string $question */
        $question = $request->input('question');
        $this->prompt($question);
    }
}
PHPEOF

    cat > app/Ai/SafeLlmOutputEscaped.php << 'PHPEOF'
<?php declare(strict_types=1);

namespace App\Ai;

use Laravel\Ai\Responses\TextResponse;

final class SafeLlmOutputEscaped
{
    /** LLM output escaped before rendering — should be clean */
    public function handle(TextResponse $response): void
    {
        echo e($response->text);
    }
}
PHPEOF

    TAINT_PSALM_CONFIG="../../tests/Application/laravel-test-psalm-taint.xml"
    set +e
    TAINT_OUTPUT=$(./vendor/bin/psalm --config="$TAINT_PSALM_CONFIG" --taint-analysis --no-cache 2>&1)
    TAINT_EXIT=$?
    set -e
    echo "$TAINT_OUTPUT"

    # Exit code 2 means "issues found" (expected). Anything else is a crash.
    if [ "$TAINT_EXIT" -ne 2 ]; then
        error "Psalm taint analysis did not find expected issues (exit code $TAINT_EXIT)"
    fi

    assert_taint UnsafeLlmOutputToSql  TaintedSql
    assert_taint UnsafeLlmOutputToHtml TaintedHtml
    assert_taint UnsafeToolArgsToSql   TaintedSql
    assert_taint UnsafePromptInjection TaintedLlmPrompt
    assert_clean SafeLlmOutputEscaped

    echo
fi

# All checks passed — mark for cleanup by the EXIT trap
PSALM_PASSED=true
