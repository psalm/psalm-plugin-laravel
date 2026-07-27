--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// PromptInjection fixtures need the optional laravel/ai integration installed (the plugin's
// laravel-ai stubs load only when Plugin::optionalIntegrationStubs() sees
// isInstalledAndSatisfies('laravel/ai', '>=0.10.0 <1.0.0')); it is not a root composer.json
// dependency (PHP ^8.3 floor would break the PHP 8.2 CI lanes). Skip rather than fail when absent.
if (!trait_exists(\Laravel\Ai\Promptable::class)) {
    echo 'skip needs laravel/ai package (optional integration, not in composer.json)';
}
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\StructuredArrayLimit;

/**
 * Known limitation, not a design choice. Both the `toArray()` return and the
 * `$structured` property carry the `input` taint, but reading a single element
 * out of the resulting array loses the edge under whole-project analysis, which
 * is how this suite and a real project run. Both fixtures below report
 * TaintedSql when Psalm analyzes the file on its own, so this is the upstream
 * hop-loss, not a missing source.
 *
 * What survives is a zero-hop consumption of the whole payload, which is why the
 * positive coverage in StructuredPayloadProperty.phpt sinks the array itself.
 *
 * Direct array access on the response object (`$response['body']`) is uncovered
 * for a different upstream reason (https://github.com/vimeo/psalm/issues/11912),
 * pinned separately by StructuredResponseArrayAccessKnownLimitation.phpt.
 *
 * Empty expectations make this a canary: it turns red once the hop survives, at
 * which point the accompanying caveats should be deleted.
 */
function readStructuredElement(\Laravel\Ai\Responses\StructuredAgentResponse $response): void {
    $payload = $response->toArray();

    if (isset($payload['body'])) {
        \Illuminate\Support\Facades\DB::select('SELECT * FROM notes WHERE body = ' . (string) $payload['body']);
    }
}

function readStructuredPropertyElement(\Laravel\Ai\Responses\StructuredAgentResponse $response): void {
    if (isset($response->structured['body'])) {
        \Illuminate\Support\Facades\DB::select('SELECT * FROM notes WHERE body = ' . (string) $response->structured['body']);
    }
}
?>
--EXPECTF--
