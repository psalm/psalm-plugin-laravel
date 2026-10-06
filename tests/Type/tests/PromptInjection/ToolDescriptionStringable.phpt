--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\ToolDescriptions;

/**
 * CanActAsTool::description() returns Stringable|string, not string. While the stub
 * narrowed it to string, every caller that handled the Stringable branch collected
 * RedundantCondition, TypeDoesNotContainType, MixedMethodCall and MixedReturnStatement.
 */
function normalizeDescription(\Laravel\Ai\Contracts\CanActAsTool $tool): string
{
    $description = $tool->description();

    return is_string($description) ? $description : $description->__toString();
}
?>
--EXPECTF--
