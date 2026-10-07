--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\Schema\ForeignKeyDefinition;

// The fluent methods come from the vendor class-level @method tags; only lock()/onDelete()/onUpdate()
// are stubbed. Pins that the slim stub re-declaration keeps the reflected @method list resolvable
// and that chains keep their receiver type.
function fluentChains(ColumnDefinition $c, ForeignKeyDefinition $f): void {
    $_nullable = $c->nullable();
    /** @psalm-check-type-exact $_nullable = ColumnDefinition&static */

    $_afterNullable = $c->after('x')->nullable(false);
    /** @psalm-check-type-exact $_afterNullable = ColumnDefinition&static */

    $_defaultUnique = $c->default(1)->unique();
    /** @psalm-check-type-exact $_defaultUnique = ColumnDefinition&static */

    // Stubbed method: the chain continues into vendor @method tags.
    $_lockThenNullable = $c->lock('none')->nullable();
    /** @psalm-check-type-exact $_lockThenNullable = ColumnDefinition&static */

    $_on = $f->on('t');
    /** @psalm-check-type-exact $_on = ForeignKeyDefinition */

    $_references = $f->references(['a']);
    /** @psalm-check-type-exact $_references = ForeignKeyDefinition */

    $_chain = $f->on('t')->references(['a'])->cascadeOnUpdate();
    /** @psalm-check-type-exact $_chain = ForeignKeyDefinition&static */

    $_onDelete = $f->onDelete('set null');
    /** @psalm-check-type-exact $_onDelete = ForeignKeyDefinition */

    $_lock = $f->lock('shared')->deferrable();
    /** @psalm-check-type-exact $_lock = ForeignKeyDefinition */
}
?>
--EXPECTF--
