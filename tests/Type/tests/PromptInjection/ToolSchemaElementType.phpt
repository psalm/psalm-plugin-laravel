--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml
--FILE--
<?php declare(strict_types=1);

namespace App\ToolSchemaTypes;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;

/** @return array<string, Type> */
function readSchema(Tool $tool, JsonSchema $schema): array
{
    // Vendor declares the element type. A `mixed` element in the stub would
    // degrade every consumer of a tool schema to an untyped array.
    /** @psalm-check-type-exact $definition = array<string, Type> */
    $definition = $tool->schema($schema);

    return $definition;
}
?>
--EXPECTF--
