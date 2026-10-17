--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.34.0');
--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

// Schema\Builder::getColumn() (Laravel 13.34+) returns `[]` for an absent column but documents only the
// full column shape, so `=== []` is a DocblockTypeContradiction. stubs/13.34.0 declares a bare `array`.

function schema_facade_get_column_may_be_empty(): bool
{
    return Schema::getColumn('users', 'missing') === [];
}

function consume_mixed(mixed $value): void { echo json_encode($value); }

// A full row or `[]`: Psalm would merge `array{...}|array{}` into one shape with every key optional
// (`array{name?: string, ...}`), so the stub declares the facade tag's bare `array`.
function schema_facade_get_column_is_a_plain_array(): void
{
    $_column = Schema::getColumn('users', 'id');
    /** @psalm-check-type-exact $_column = array<array-key, mixed> */

    if ($_column !== [] && isset($_column['name'])) {
        consume_mixed($_column['name']);
    }
}
?>
--EXPECTF--
