--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// PromptInjection fixtures need the optional laravel/ai integration installed (the plugin's
// laravel-ai stubs load only when Plugin::optionalIntegrationStubs() sees
// isInstalledAndSatisfies('laravel/ai', '>=0.11.0 <1.0.0')); it is not a root composer.json
// dependency (PHP ^8.3 floor would break the PHP 8.2 CI lanes). Skip rather than fail when absent.
if (!trait_exists(\Laravel\Ai\Promptable::class)) {
    echo 'skip needs laravel/ai package (optional integration, not in composer.json)';
}
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\StreamableCasts;

function castStreamableResponse(\Laravel\Ai\Responses\StreamableAgentResponse $response): string {
    // StreamableAgentResponse is the one response class upstream does not give a
    // __toString(). The stub used to declare one anyway, which made Psalm accept
    // this cast and hid a runtime fatal. The report below is the point of the test:
    // a stub must not invent API, even to hang a taint annotation off it.
    return (string) $response;
}
?>
--EXPECTF--
InvalidCast on line %d: Laravel\Ai\Responses\StreamableAgentResponse cannot be cast to string
