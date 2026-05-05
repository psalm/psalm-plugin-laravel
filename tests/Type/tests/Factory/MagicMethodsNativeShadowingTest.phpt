--FILE--
<?php declare(strict_types=1);

use App\Factories\WorkOrderFactory;

/**
 * Regression coverage for the native-method shadowing guard in
 * FactoryMagicMethodHandler::magicCallApplies().
 *
 * Psalm consults registered method providers for *every* method call on a class
 * once any closure is registered there, not just for unrecognized methods. The
 * handler must therefore explicitly decline when the call resolves to a real
 * declared method on the factory class — including methods inherited from the
 * Factory base — so that real signatures (parameter requirements, return types)
 * are preserved.
 *
 * Test strategy: call real Factory methods with too few arguments. Psalm should
 * report TooFewArguments using the real method signatures. If the handler's
 * variadic-mixed params provider were shadowing them, those errors would be
 * suppressed and the calls would silently type-check.
 *
 * Confirmed shadow risks if the guard is removed:
 *   - hasAttached($factory, $pivot, $relationship)  — `has` prefix + `attached` model relation
 *   - forEachSequence(...$sequence)                 — `for` prefix + `eachSequence` relation
 *
 * has() and for() at exact length 3 are also caught by extractRelationshipName's
 * length>=4 guard, but the methodExists() check is the primary defense.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/696
 */
function test_real_has_arity_preserved(WorkOrderFactory $factory): void
{
    $factory->has();
}

function test_real_for_arity_preserved(WorkOrderFactory $factory): void
{
    $factory->for();
}

function test_real_hasAttached_arity_preserved(WorkOrderFactory $factory): void
{
    $factory->hasAttached();
}
?>
--EXPECTF--
TooFewArguments on line %d: Too few arguments for App\Factories\WorkOrderFactory::has%s
TooFewArguments on line %d: Too few arguments for%shas saw 0
TooFewArguments on line %d: Too few arguments for App\Factories\WorkOrderFactory::for%s
TooFewArguments on line %d: Too few arguments for%sfor saw 0
TooFewArguments on line %d: Too few arguments for App\Factories\WorkOrderFactory::hasAttached%s
TooFewArguments on line %d: Too few arguments for%shasattached saw 0
