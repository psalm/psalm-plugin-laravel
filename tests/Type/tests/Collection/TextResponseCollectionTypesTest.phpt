--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// PromptInjection fixtures need the optional laravel/ai integration installed (the plugin's
// laravel-ai stubs load only when Plugin::optionalIntegrationStubs() sees
// LaravelAiIntegration::isEnabled()); it is not a root composer.json
// dependency (PHP ^8.3 floor would break the PHP 8.2 CI lanes). Skip rather than fail when absent.
if (!\Psalm\LaravelPlugin\Internal\LaravelAiIntegration::isEnabled() || !trait_exists(\Laravel\Ai\Promptable::class)) {
    echo 'skip needs supported laravel/ai package (>=0.11.0 <1.0.0)';
}
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml
--FILE--
<?php declare(strict_types=1);

namespace App\TextResponseCollectionTypes;

use Illuminate\Support\Collection;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\StructuredAgentResponse;

function attachInferredStep(StructuredAgentResponse $response, Step $step): void
{
    // Regression for #1426: Collection<0, Step> must satisfy the vendor Collection<int, Step> contract.
    $response->withSteps(new Collection([$step]));
}

/**
 * StructuredAgentResponse inherits these fluent setters and properties from TextResponse. The
 * integration stub must preserve their vendor generic types when it re-declares TextResponse.
 *
 * @param Collection<int, Message> $messages
 * @param Collection<int, ToolCall> $toolCalls
 * @param Collection<int, ToolResult> $toolResults
 * @param Collection<int, Step> $steps
 * @param Collection<int, PendingApproval> $pendingApprovals
 */
function attachResponseContext(
    StructuredAgentResponse $response,
    Collection $messages,
    Collection $toolCalls,
    Collection $toolResults,
    Collection $steps,
    Collection $pendingApprovals,
): void {
    $response
        ->withMessages($messages)
        ->withToolCallsAndResults($toolCalls, $toolResults)
        ->withSteps($steps)
        ->withPendingApprovals($pendingApprovals);

    $_messages = $response->messages;
    /** @psalm-check-type-exact $_messages = Collection<int, Message> */

    $_toolCalls = $response->toolCalls;
    /** @psalm-check-type-exact $_toolCalls = Collection<int, ToolCall> */

    $_toolResults = $response->toolResults;
    /** @psalm-check-type-exact $_toolResults = Collection<int, ToolResult> */

    $_steps = $response->steps;
    /** @psalm-check-type-exact $_steps = Collection<int, Step> */

    $_pendingApprovals = $response->pendingApprovals;
    /** @psalm-check-type-exact $_pendingApprovals = Collection<int, PendingApproval> */
}
?>
--EXPECTF--
