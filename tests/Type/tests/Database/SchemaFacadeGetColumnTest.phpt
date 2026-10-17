--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.34.0');
--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

// Schema\Builder::getColumn() (Laravel 13.34+) returns `[]` for an absent column but documents only the
// full column shape, so `=== []` is a DocblockTypeContradiction. stubs/13.34.0 restates the facade tag's bare `array` on the facade only.

function schema_facade_get_column_may_be_empty(): bool
{
    return Schema::getColumn('users', 'missing') === [];
}

function consume_mixed(mixed $value): void { echo json_encode($value); }

// A full row or `[]`: Psalm would merge `array{...}|array{}` into one shape with every key optional
// (`array{name?: string, ...}`), so the facade stub restates the tag's bare `array`.
function schema_facade_get_column_is_a_plain_array(): void
{
    $_column = Schema::getColumn('users', 'id');
    /** @psalm-check-type-exact $_column = array<array-key, mixed> */

    if ($_column !== [] && isset($_column['name'])) {
        consume_mixed($_column['name']);
    }
}

// The Builder INSTANCE keeps Laravel's own docblock (the carve-out is facade-only), so both the direct
// instance and `Schema::connection()` calls read the typed row.
function schema_builder_instance_get_column_keeps_laravel_docblock(\Illuminate\Database\Schema\Builder $schema): string
{
    return $schema->getColumn('users', 'id')['name'] ?? '';
}

function schema_connection_get_column_keeps_laravel_docblock(): string
{
    return Schema::connection(null)->getColumn('users', 'id')['name'] ?? '';
}
?>
--EXPECTF--
