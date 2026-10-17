--FILE--
<?php declare(strict_types=1);

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
    }

    consume_string_list(Schema::getTableListing());
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
