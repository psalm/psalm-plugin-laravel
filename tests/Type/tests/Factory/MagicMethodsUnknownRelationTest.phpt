--FILE--
<?php declare(strict_types=1);

use App\Factories\WorkOrderFactory;

/**
 * Negative coverage: a for{Foo}() / has{Foo}() call whose tail does not match a
 * relationship method on the model must surface UndefinedMagicMethod. Without
 * this regression, a future change that loosens the relation check (e.g. always
 * returning true) would silently accept any prefix call.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/696
 */
function test_unknown_for_relation_is_rejected(WorkOrderFactory $factory): void
{
    $factory->forNonexistentRelation();
}

function test_unknown_has_relation_is_rejected(WorkOrderFactory $factory): void
{
    $factory->hasNonexistentRelation(3);
}
?>
--EXPECTF--
UndefinedMagicMethod on line %d: Magic method App\Factories\WorkOrderFactory::fornonexistentrelation does not exist
UndefinedMagicMethod on line %d: Magic method App\Factories\WorkOrderFactory::hasnonexistentrelation does not exist
