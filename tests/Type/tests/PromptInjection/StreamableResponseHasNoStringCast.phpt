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
    // this cast and hid a runtime fatal. On Psalm 7/master this reports InvalidCast;
    // Psalm 6 does not flag casting an object without __toString() to string at all
    // (confirmed empirically, not a plugin gap) — silence on this branch is correct.
    return (string) $response;
}
?>
--EXPECTF--
