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
 * methods, so Builder's types apply. Where Laravel's Builder docblock contradicts the driver processors
 * (or is absent), stubs/common/Database/Schema/Builder.phpstub declares the runtime-true shape.
 */
function schema_facade_introspection_resolves_through_builder(): void
{
    foreach (Schema::getTables() as $table) {
        consume_string($table['name']);
    }

    foreach (Schema::getViews('main') as $view) {
        consume_string($view['name']);
    }

    consume_string_list(Schema::getTableListing());
}

/** SQL Server's `sys.sql_modules.definition` is NULL for encrypted views; Laravel documents `string`. */
function schema_facade_get_views_definition_may_be_null(): bool
{
    foreach (Schema::getViews() as $view) {
        if ($view['definition'] === null) {
            return true;
        }
    }

    return false;
}

/** Laravel 12.x and 13.0-13.7 omit `collation`; every line documents `generation.type` as non-null. */
function schema_facade_get_columns_follows_the_processors(): void
{
    foreach (Schema::getColumns('users') as $column) {
        consume_string($column['name']);

        $_collation = $column['collation'];
        /** @psalm-check-type-exact $_collation = null|string */
        echo $_collation;

        $generation = $column['generation'];

        if ($generation !== null) {
            $_generationType = $generation['type'];
            /** @psalm-check-type-exact $_generationType = null|string */
            echo $_generationType;
        }
    }
}

/** Every line documents `type: string`, but SQLite always emits null; SQLite's result is not a list. */
function schema_facade_get_indexes_follows_the_processors(): void
{
    $_indexes = Schema::getIndexes('users');
    /** @psalm-check-type-exact $_indexes = array<int, array{columns: list<string>, name: string, primary: bool, type: null|string, unique: bool}> */

    foreach ($_indexes as $index) {
        consume_string($index['name']);
        consume_string_list($index['columns']);
    }
}

/** Laravel 12.x and 13.0-13.6 document a bare `array`; SQLite emits a null constraint name. */
function schema_facade_get_foreign_keys_is_typed_on_every_line(): void
{
    foreach (Schema::getForeignKeys('posts') as $foreignKey) {
        consume_string_list($foreignKey['columns']);

        $_name = $foreignKey['name'];
        /** @psalm-check-type-exact $_name = null|string */
        echo $_name;
    }
}

/** Laravel < 13.7 repeats the `type` key and < 13.8 omits `schema_qualified_name`; Postgres emits null codes. */
function schema_facade_get_types_follows_the_processors(): void
{
    foreach (Schema::getTypes() as $type) {
        consume_string($type['name']);
        consume_string($type['schema_qualified_name']);

        $_category = $type['category'];
        /** @psalm-check-type-exact $_category = null|string */
        echo $_category;

        $_kind = $type['type'];
        /** @psalm-check-type-exact $_kind = null|string */
        echo $_kind;
    }
}

/** `Schema::connection()` returns the Builder itself, so the instance path shares the corrected types. */
function schema_connection_instance_calls_share_the_corrected_types(): void
{
    foreach (Schema::connection(null)->getColumns('users') as $column) {
        $generation = $column['generation'];

        if ($generation !== null) {
            $_generationType = $generation['type'];
            /** @psalm-check-type-exact $_generationType = null|string */
            echo $_generationType;
        }
    }
}

/**
 * Laravel 12.20-12.21 document the resolver as `Closure(string, Closure, string): Blueprint|null`, which
 * contradicts createBlueprint() (`$connection, $table, $callback`).
 */
function schema_facade_blueprint_resolver_accepts_runtime_resolver(): void
{
    Schema::blueprintResolver(static fn (Connection $connection, string $table): Blueprint => new Blueprint($connection, $table));
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
