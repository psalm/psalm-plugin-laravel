#!/usr/bin/env bash

# LLM Prompt Taint Analysis Test Script
# Requires PHP 8.3+ and laravel/ai ^0.5
# Tests that user input flowing into LLM prompts triggers TaintedLlmPrompt,
# and LLM output flowing into SQL/HTML sinks triggers the corresponding issues.
#
# Usage:
#   bash tests/Application/laravel_ai.sh

set -e
set -o pipefail
set -u

LARAVEL_INSTALLER_VERSION="${LARAVEL_INSTALLER_VERSION:-13.1.0}"

RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m'

info()  { echo -e "${GREEN}$1${NC}"; }
error() { echo -e "${RED}Error: $1${NC}" >&2; exit 1; }

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

CURRENT_SCRIPT_PATH="$( cd "$(dirname "$0")" ; pwd -P )"
PROJECT_ROOT="$(dirname "$(dirname "$CURRENT_SCRIPT_PATH")")"
APP_INSTALLATION_PATH="$PROJECT_ROOT/tests-app/laravel-ai-example"
RELATIVE_PATH="${APP_INSTALLATION_PATH#"$PROJECT_ROOT"/}"

PSALM_PASSED=false

cleanup() {
    [ -d "$APP_INSTALLATION_PATH" ] || return 0
    if [ "$PSALM_PASSED" = true ]; then
        rm -rf "$APP_INSTALLATION_PATH"
        return
    fi
    info "Test app preserved at $APP_INSTALLATION_PATH for debugging"
    info "Delete manually: rm -rf $APP_INSTALLATION_PATH"
}

if [ -d "$APP_INSTALLATION_PATH" ]; then
    info "Removing previous installation..."
    rm -rf "$APP_INSTALLATION_PATH"
fi

trap cleanup EXIT

info "Creating Laravel project at ${RELATIVE_PATH} (installer v${LARAVEL_INSTALLER_VERSION}) ..."
composer create-project --quiet --prefer-dist laravel/laravel "$APP_INSTALLATION_PATH" "$LARAVEL_INSTALLER_VERSION"
cd "$APP_INSTALLATION_PATH"

info "Installing laravel/ai"
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

info "Adding psalm/plugin-laravel from source"
composer config repositories.0 '{"type": "path", "url": "../../"}'
composer config minimum-stability 'dev'
COMPOSER_MEMORY_LIMIT=-1 composer require --dev "psalm/plugin-laravel:*" --update-with-all-dependencies

info "Running taint analysis on AI test files"
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

PSALM_PASSED=true
