--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

function consume_string(string $value): void { echo $value; }

/**
 * @param list<string> $value
 */
function consume_string_list(array $value): void { echo implode(",", $value); }

/**
 * The facade's generated `@method static array ...` tags lose to the `@mixin Schema\Builder` real
 * methods, so Builder's documented `list<array{...}>` types apply. Only keys present in every
 * supported Laravel line are read; the full shapes differ between versions.
 */
function schema_facade_introspection_resolves_through_builder(): void
{
    foreach (Schema::getTables() as $table) {
        consume_string($table['name']);
    }

    foreach (Schema::getViews('main') as $view) {
        consume_string($view['name']);
    }

    foreach (Schema::getColumns('users') as $column) {
        consume_string($column['name']);
    }

    foreach (Schema::getIndexes('users') as $index) {
        consume_string($index['name']);
        consume_string_list($index['columns']);
    }

    foreach (Schema::getTypes() as $type) {
        consume_string($type['name']);
        consume_string($type['schema_qualified_name']);

        // Postgres custom categories map to null in PostgresProcessor::processTypes().
        $_category = $type['category'];
        /** @psalm-check-type-exact $_category = null|string */
        echo $_category;
    }

    consume_string_list(Schema::getTableListing());
}

/**
 * Builder::blueprintResolver() documents a resolver taking `(string, Closure, string)` on Laravel 12.20-12.21,
 * which contradicts createBlueprint() (`$connection, $table, $callback`). The facade stub keeps the
 * facade tag's plain `\Closure`, so a valid resolver is accepted on every supported line.
 */
function schema_facade_blueprint_resolver_accepts_runtime_resolver(): void
{
    Schema::blueprintResolver(static fn (Connection $_connection, string $table): Blueprint => new Blueprint($_connection, $table));
}

/** Methods Builder does not type more precisely keep their facade types. */
function schema_facade_other_methods_keep_facade_types(): void
{
    $_hasColumn = Schema::hasColumn('users', 'id');
    /** @psalm-check-type-exact $_hasColumn = bool */

    $_columnListing = Schema::getColumnListing('users');
    /** @psalm-check-type-exact $_columnListing = list<string> */

    $_schemaName = Schema::getCurrentSchemaName();
    /** @psalm-check-type-exact $_schemaName = null|string */
}
?>
--EXPECTF--
