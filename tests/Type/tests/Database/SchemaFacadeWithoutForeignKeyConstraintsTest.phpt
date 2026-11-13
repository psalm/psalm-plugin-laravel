--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// Builder::withoutForeignKeyConstraints() declares `@template TReturn` from Laravel 12.50.0; before that it returns `mixed`.
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('12.50.0');
--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/** The generic callback of Builder::withoutForeignKeyConstraints() is inferred through the mixin. */
function schema_facade_without_foreign_key_constraints_infers_the_callback_return(): int
{
    return Schema::withoutForeignKeyConstraints(static fn (): int => 1);
}
?>
--EXPECTF--
