--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.7.0');
--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

// Schema\Builder::getForeignKeys() is documented as a `list<array{...}>` only from Laravel 13.7;
// earlier lines return a bare `array`, which the `@mixin` fall-through leaves as the facade tag says.

/** @param list<string> $value */
function consume_string_list(array $value): void { echo implode(",", $value); }

function schema_facade_get_foreign_keys_resolves_through_builder(): void
{
    foreach (Schema::getForeignKeys('posts') as $foreignKey) {
        consume_string_list($foreignKey['columns']);
        $_name = $foreignKey['name'];
        /** @psalm-check-type-exact $_name = null|string */
        echo $_name;
    }
}
?>
--EXPECTF--
