--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
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
