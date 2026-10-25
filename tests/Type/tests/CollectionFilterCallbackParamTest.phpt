--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * filter()/where()/reject() call the callback for every element, and a class-typed first
 * parameter throws TypeError for any non-instance, so every surviving element is an instance
 * of that class. Only the return type is narrowed: argument checking still reports the
 * coercion (pinned in the expected output below).
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1912
 */
final class CollectionFilterCallbackParamTest extends CfcpAnimal
{
    /** @param Collection<int, CfcpAnimal> $animals */
    public function narrowsSupertypeToParamClass(Collection $animals): void
    {
        $_dogs = $animals->filter(fn (CfcpDog $_d): bool => true);
        /** @psalm-check-type-exact $_dogs = Collection<int, CfcpDog>&static */
    }

    /** @param Collection<array-key, mixed> $moves */
    public function narrowsMixedToParamClass(Collection $moves): void
    {
        $_dogs = $moves->filter(fn (CfcpDog $_d): bool => true);
        /** @psalm-check-type-exact $_dogs = Collection<array-key, CfcpDog>&static */
    }

    public function narrowsUntypedCollectionToParamClass(Collection $moves): void
    {
        $_dogs = $moves->filter(fn (CfcpDog $_d): bool => true);
        /** @psalm-check-type-exact $_dogs = Collection<array-key, CfcpDog>&static */
    }

    /** @param Collection<int, CfcpDog> $dogs */
    public function unrelatedParamKeepsResult(Collection $dogs): void
    {
        $_result = $dogs->filter(fn (CfcpItem $_i): bool => true);
        /** @psalm-check-type-exact $_result = Collection<int, CfcpDog>&static */
    }

    /** @param Collection<int, CfcpAnimal> $animals */
    public function unrelatedParamOnSupertypeKeepsResult(Collection $animals): void
    {
        $_result = $animals->filter(fn (CfcpItem $_i): bool => true);
        /** @psalm-check-type-exact $_result = Collection<int, CfcpAnimal>&static */
    }

    /** @param Collection<int, CfcpDog|null> $dogs */
    public function nullableParamKeepsNullWhenValueHasNull(Collection $dogs): void
    {
        $_result = $dogs->filter(fn (?CfcpDog $_d): bool => true);
        /** @psalm-check-type-exact $_result = Collection<int, CfcpDog|null>&static */
    }

    /** @param Collection<int, CfcpAnimal> $animals */
    public function nullableParamDoesNotInventNull(Collection $animals): void
    {
        $_result = $animals->filter(fn (?CfcpDog $_d): bool => true);
        /** @psalm-check-type-exact $_result = Collection<int, CfcpDog>&static */
    }

    /** @param Collection<int, mixed> $items */
    public function nullableParamOnMixedKeepsNull(Collection $items): void
    {
        $_result = $items->filter(fn (?CfcpDog $_d): bool => true);
        /** @psalm-check-type-exact $_result = Collection<int, CfcpDog|null>&static */
    }

    /** @param Collection<int, CfcpDog|null> $dogs */
    public function nonNullableParamDropsNull(Collection $dogs): void
    {
        $_result = $dogs->filter(fn (CfcpDog $_d): bool => true);
        /** @psalm-check-type-exact $_result = Collection<int, CfcpDog>&static */
    }

    /** @param Collection<int, CfcpDog|null> $dogs */
    public function defaultNullParamKeepsNull(Collection $dogs): void
    {
        $_result = $dogs->filter(fn (CfcpDog $_d = null): bool => true);
        /** @psalm-check-type-exact $_result = Collection<int, CfcpDog|null>&static */
    }

    /** @param Collection<int, CfcpAnimal> $animals */
    public function rejectNarrows(Collection $animals): void
    {
        $_dogs = $animals->reject(fn (CfcpDog $_d): bool => false);
        /** @psalm-check-type-exact $_dogs = Collection<int, CfcpDog>&static */
    }

    /** @param LazyCollection<int, CfcpAnimal> $animals */
    public function lazyCollectionNarrows(LazyCollection $animals): void
    {
        $_dogs = $animals->filter(fn (CfcpDog $_d): bool => true);
        /** @psalm-check-type-exact $_dogs = LazyCollection<int, CfcpDog>&static */
    }

    /** @param Collection<int, CfcpAnimal> $animals */
    public function whereNarrows(Collection $animals): void
    {
        $_dogs = $animals->where(fn (CfcpDog $_d): bool => true);
        /** @psalm-check-type-exact $_dogs = Collection<int, CfcpDog>&static */
    }

    /** @param Collection<int, CfcpNamed> $named */
    public function interfaceValueNarrowsToParamClass(Collection $named): void
    {
        $_result = $named->filter(fn (CfcpAnimal $_a): bool => true);
        /** @psalm-check-type-exact $_result = Collection<int, CfcpAnimal>&static */
    }

    /** @param Collection<int, CfcpSuit|CfcpDog> $items */
    public function enumParamNarrows(Collection $items): void
    {
        $_result = $items->filter(fn (CfcpSuit $_s): bool => true);
        /** @psalm-check-type-exact $_result = Collection<int, CfcpSuit>&static */
    }

    /** @param Collection<int, CfcpAnimal|CfcpItem> $items */
    public function dropsUnrelatedAtomics(Collection $items): void
    {
        $_result = $items->filter(fn (CfcpDog $_d): bool => true);
        /** @psalm-check-type-exact $_result = Collection<int, CfcpDog>&static */
    }

    /** @param Collection<int, CfcpAnimal> $animals */
    public function secondParamDoesNotAffectNarrowing(Collection $animals): void
    {
        $_dogs = $animals->filter(function (CfcpDog $_d, int $_key): bool {
            return true;
        });
        /** @psalm-check-type-exact $_dogs = Collection<int, CfcpDog>&static */
    }

    /** @param \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model> $models */
    public function eloquentCollectionNarrows(\Illuminate\Database\Eloquent\Collection $models): void
    {
        $_result = $models->filter(fn (CfcpModel $_m): bool => true);
        /** @psalm-check-type-exact $_result = \Illuminate\Database\Eloquent\Collection<int, CfcpModel>&static */
    }

    /** @param Collection<int, CfcpAnimal|null> $animals */
    public function predicateAloneStillNarrows(Collection $animals): void
    {
        $_result = $animals->filter(fn (?CfcpAnimal $_a): bool => $_a instanceof CfcpDog);
        /** @psalm-check-type-exact $_result = Collection<int, CfcpDog>&static */
    }

    /** @param Collection<int, CfcpAnimal|CfcpItem> $items */
    public function paramNarrowingFeedsPredicate(Collection $items): void
    {
        // The predicate matcher alone declines (CfcpItem is not a CfcpAnimal, so the callback would throw TypeError); it only runs on the param-narrowed TValue.
        $_result = $items->filter(fn (CfcpAnimal $_a): bool => $_a instanceof CfcpDog);
        /** @psalm-check-type-exact $_result = Collection<int, CfcpDog>&static */
    }

    /** @param Collection<int, mixed> $items */
    public function mixedValueWithInstanceofPredicate(Collection $items): void
    {
        $_result = $items->filter(fn (?CfcpAnimal $_a): bool => $_a instanceof CfcpDog);
        /** @psalm-check-type-exact $_result = Collection<int, CfcpDog>&static */
    }

    /** @param Collection<int, CfcpAnimal|CfcpItem> $animals */
    public function unrecognizedPredicateStillKeepsParamNarrowing(Collection $animals): void
    {
        $_result = $animals->filter(fn (CfcpAnimal $_a): bool => $_a->name !== '');
        /** @psalm-check-type-exact $_result = Collection<int, CfcpAnimal>&static */
    }

    /** @param Collection<int, CfcpAnimal> $animals */
    public function multiStatementBodyKeepsParamNarrowing(Collection $animals): void
    {
        $_result = $animals->filter(function (CfcpDog $_d): bool {
            $_unused = $_d;

            return true;
        });
        /** @psalm-check-type-exact $_result = Collection<int, CfcpDog>&static */
    }

    /** @param Collection<int, CfcpAnimal> $animals */
    public function rejectGetsNoPredicateNarrowing(Collection $animals): void
    {
        $_result = $animals->reject(fn (CfcpAnimal $_a): bool => $_a instanceof CfcpDog);
        /** @psalm-check-type-exact $_result = Collection<int, CfcpAnimal>&static */
    }

    /** @param Collection<int, int|string> $items */
    public function scalarParamDeclines(Collection $items): void
    {
        $_result = $items->filter(fn (int $_i): bool => true);
        /** @psalm-check-type-exact $_result = Collection<int, int|string>&static */
    }

    /** @param Collection<int, CfcpAnimal> $animals */
    public function untypedParamDeclines(Collection $animals): void
    {
        $_result = $animals->filter(fn ($_a): bool => true);
        /** @psalm-check-type-exact $_result = Collection<int, CfcpAnimal>&static */
    }

    /** @param Collection<int, CfcpAnimal> $animals */
    public function mixedParamDeclines(Collection $animals): void
    {
        $_result = $animals->filter(fn (mixed $_a): bool => true);
        /** @psalm-check-type-exact $_result = Collection<int, CfcpAnimal>&static */
    }

    /** @param Collection<int, CfcpAnimal|int> $items */
    public function mixedClassAndScalarParamDeclines(Collection $items): void
    {
        $_result = $items->filter(fn (CfcpDog|int $_d): bool => true);
        /** @psalm-check-type-exact $_result = Collection<int, CfcpAnimal|int>&static */
    }

    /** @param Collection<int, object> $items */
    public function objectParamDeclines(Collection $items): void
    {
        $_result = $items->filter(fn (object $_o): bool => true);
        /** @psalm-check-type-exact $_result = Collection<int, object>&static */
    }

    /** @param Collection<int, CfcpAnimal> $animals */
    public function firstIsNotNarrowed(Collection $animals): void
    {
        $_first = $animals->first(fn (CfcpDog $_d): bool => true);
        /** @psalm-check-type-exact $_first = CfcpAnimal|null */
    }

    /** @param Collection<int, CfcpAnimal> $animals */
    public function twoArgumentsDecline(Collection $animals): void
    {
        $_result = $animals->where(fn (CfcpDog $_d): bool => true, '=');
        /** @psalm-check-type-exact $_result = Collection<int, CfcpAnimal>&static */
    }

    /** @param Collection<int, CfcpAnimal> $animals */
    public function noClosureDeclines(Collection $animals, callable $cb): void
    {
        $_result = $animals->reject($cb);
        /** @psalm-check-type-exact $_result = Collection<int, CfcpAnimal>&static */
    }

    /** @param Collection<int, CfcpAnimal> $animals */
    public function variadicParamDeclines(Collection $animals): void
    {
        $_result = $animals->filter(fn (CfcpDog ...$_d): bool => true);
        /** @psalm-check-type-exact $_result = Collection<int, CfcpAnimal>&static */
    }

    /** @param Collection<int, CfcpAnimal> $animals */
    public function firstClassCallableDeclines(Collection $animals): void
    {
        $_result = $animals->filter(self::isDog(...));
        /** @psalm-check-type-exact $_result = Collection<int, CfcpAnimal>&static */
    }

    /** @param CfcpWithMetadata<CfcpDog, CfcpAnimal> $c */
    public function subclassWithExtraTemplateKeepsType(CfcpWithMetadata $c): void
    {
        $_result = $c->filter(fn (CfcpDog $_d): bool => true);
        /** @psalm-check-type-exact $_result = CfcpWithMetadata<CfcpDog, CfcpAnimal> */
    }

    /** @param CfcpBag<int, CfcpAnimal> $bag */
    public function layoutPreservingSubclassKeepsType(CfcpBag $bag): void
    {
        $_result = $bag->filter(fn (CfcpDog $_d): bool => true);
        /** @psalm-check-type-exact $_result = CfcpBag<int, CfcpAnimal> */
    }

    /**
     * @template C of Collection<int, CfcpAnimal>
     * @param C $c
     * @return C
     */
    public function templateReceiverKeepsType(Collection $c): Collection
    {
        $_result = $c->filter(fn (CfcpDog $_d): bool => true);
        // Returning the result pins that it stays assignable to C (no InvalidReturnStatement).
        return $_result;
    }

    /** @param Collection<int, CfcpAnimal>&CfcpNamed $c */
    public function intersectionReceiverKeepsType(Collection $c): void
    {
        $_result = $c->filter(fn (CfcpDog $_d): bool => true);
        /** @psalm-check-type-exact $_result = Collection<int, CfcpAnimal>&CfcpNamed&static */
    }

    /** @param Collection<int, CfcpAnimal>|\Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model> $c */
    public function unionReceiverStillNarrowsBaseBranch(Collection $c): void
    {
        $_result = $c->filter(fn (CfcpDog $_d): bool => true);
        /** @psalm-check-type-exact $_result = Collection<int, CfcpDog>&static|\Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model>&static */
    }

    /** @param Collection<int, mixed> $items */
    public function parentParamDeclines(Collection $items): void
    {
        $_result = $items->reject(fn (parent $_x): bool => false);
        /** @psalm-check-type-exact $_result = Collection<int, mixed>&static */
    }

    /** @param Collection<int, mixed> $items */
    public function selfParamDeclines(Collection $items): void
    {
        $_result = $items->reject(fn (self $_x): bool => false);
        /** @psalm-check-type-exact $_result = Collection<int, mixed>&static */
    }

    /**
     * reject()/where() are declared on EnumeratesValues and do not reach the handler for an
     * Eloquent\Collection receiver (filter() does), so these keep the declared type.
     *
     * @param \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model> $models
     */
    public function eloquentCollectionRejectIsNotHandled(\Illuminate\Database\Eloquent\Collection $models): void
    {
        $_result = $models->reject(fn (CfcpModel $_m): bool => false);
        /** @psalm-check-type-exact $_result = \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model>&static */
    }

    /** @param \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model> $models */
    public function eloquentCollectionWhereIsNotHandled(\Illuminate\Database\Eloquent\Collection $models): void
    {
        $_result = $models->where(fn (CfcpModel $_m): bool => true);
        /** @psalm-check-type-exact $_result = \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model>&static */
    }

    private static function isDog(CfcpDog $_d): bool
    {
        return true;
    }
}

interface CfcpNamed {}

abstract class CfcpAnimal implements CfcpNamed
{
    public string $name = '';
}

final class CfcpDog extends CfcpAnimal {}

final class CfcpItem {}

enum CfcpSuit
{
    case Hearts;
}

/**
 * @template V
 * @template M
 * @extends Collection<int, V>
 */
final class CfcpWithMetadata extends Collection {}

/**
 * @template TKey of array-key
 * @template TValue
 * @extends Collection<TKey, TValue>
 */
final class CfcpBag extends Collection {}

abstract class CfcpModel extends \Illuminate\Database\Eloquent\Model {}
?>
--EXPECTF--
ArgumentTypeCoercion on line %d: Type CfcpAnimal should be a subtype of CfcpDog
ArgumentTypeCoercion on line %d: Argument 1 of Illuminate\Support\Collection::filter expects callable[impure](CfcpAnimal, int):bool|null, but parent type Closure[pure](CfcpDog):true provided
MixedArgumentTypeCoercion on line %d: Type mixed should be a subtype of CfcpDog
MixedArgumentTypeCoercion on line %d: Type mixed should be a subtype of CfcpDog
InvalidArgument on line %d: Argument 1 of Illuminate\Support\Collection::filter expects callable[impure](CfcpDog, int):bool|null, but Closure[pure](CfcpItem):true provided
InvalidArgument on line %d: Argument 1 of Illuminate\Support\Collection::filter expects callable[impure](CfcpAnimal, int):bool|null, but Closure[pure](CfcpItem):true provided
ArgumentTypeCoercion on line %d: Type CfcpAnimal should be a subtype of CfcpDog
ArgumentTypeCoercion on line %d: Argument 1 of Illuminate\Support\Collection::filter expects callable[impure](CfcpAnimal, int):bool|null, but parent type Closure[pure](CfcpDog|null):true provided
MixedArgumentTypeCoercion on line %d: Type mixed should be a subtype of CfcpDog|null
InvalidArgument on line %d: Type CfcpDog|null should be a subtype of CfcpDog
InvalidArgument on line %d: Argument 1 of Illuminate\Support\Collection::filter expects callable[impure](CfcpDog|null, int):bool|null, but Closure[pure](CfcpDog):true provided
ArgumentTypeCoercion on line %d: Type CfcpAnimal should be a subtype of CfcpDog
ArgumentTypeCoercion on line %d: Argument 1 of Illuminate\Support\Collection::reject expects CfcpAnimal|bool|callable[impure](CfcpAnimal, int):bool, but parent type Closure[pure](CfcpDog):false provided
ArgumentTypeCoercion on line %d: Type CfcpAnimal should be a subtype of CfcpDog
ArgumentTypeCoercion on line %d: Argument 1 of Illuminate\Support\LazyCollection::filter expects callable[impure](CfcpAnimal, int):bool|null, but parent type Closure[pure](CfcpDog):true provided
ArgumentTypeCoercion on line %d: Type CfcpNamed should be a subtype of CfcpAnimal
ArgumentTypeCoercion on line %d: Argument 1 of Illuminate\Support\Collection::filter expects callable[impure](CfcpNamed, int):bool|null, but parent type Closure[pure](CfcpAnimal):true provided
InvalidArgument on line %d: Type CfcpDog|CfcpSuit should be a subtype of CfcpSuit
InvalidArgument on line %d: Argument 1 of Illuminate\Support\Collection::filter expects callable[impure](CfcpDog|CfcpSuit, int):bool|null, but Closure[pure](CfcpSuit):true provided
ArgumentTypeCoercion on line %d: Type CfcpAnimal|CfcpItem should be a subtype of CfcpDog
ArgumentTypeCoercion on line %d: Argument 1 of Illuminate\Support\Collection::filter expects callable[impure](CfcpAnimal|CfcpItem, int):bool|null, but parent type Closure[pure](CfcpDog):true provided
ArgumentTypeCoercion on line %d: Type CfcpAnimal should be a subtype of CfcpDog
ArgumentTypeCoercion on line %d: Argument 1 of Illuminate\Support\Collection::filter expects callable[impure](CfcpAnimal, int):bool|null, but parent type Closure[pure](CfcpDog, int):true provided
ArgumentTypeCoercion on line %d: Type Illuminate\Database\Eloquent\Model should be a subtype of CfcpModel
ArgumentTypeCoercion on line %d: Argument 1 of Illuminate\Database\Eloquent\Collection::filter expects callable[impure](Illuminate\Database\Eloquent\Model, int):bool|null, but parent type Closure[pure](CfcpModel):true provided
InvalidArgument on line %d: Type CfcpAnimal|CfcpItem should be a subtype of CfcpAnimal
InvalidArgument on line %d: Argument 1 of Illuminate\Support\Collection::filter expects callable[impure](CfcpAnimal|CfcpItem, int):bool|null, but Closure[pure](CfcpAnimal):bool provided
MixedArgumentTypeCoercion on line %d: Type mixed should be a subtype of CfcpAnimal|null
InvalidArgument on line %d: Type CfcpAnimal|CfcpItem should be a subtype of CfcpAnimal
InvalidArgument on line %d: Argument 1 of Illuminate\Support\Collection::filter expects callable[impure](CfcpAnimal|CfcpItem, int):bool|null, but Closure[read-props](CfcpAnimal):bool provided
ArgumentTypeCoercion on line %d: Type CfcpAnimal should be a subtype of CfcpDog
ArgumentTypeCoercion on line %d: Argument 1 of Illuminate\Support\Collection::filter expects callable[impure](CfcpAnimal, int):bool|null, but parent type Closure[pure](CfcpDog):true provided
InvalidScalarArgument on line %d: Type int|string should be a subtype of int
InvalidScalarArgument on line %d: Argument 1 of Illuminate\Support\Collection::filter expects callable[impure](int|string, int):bool|null, but Closure[pure](int):true provided
ArgumentTypeCoercion on line %d: Type CfcpAnimal|int should be a subtype of CfcpDog|int
ArgumentTypeCoercion on line %d: Argument 1 of Illuminate\Support\Collection::filter expects callable[impure](CfcpAnimal|int, int):bool|null, but parent type Closure[pure](CfcpDog|int):true provided
ArgumentTypeCoercion on line %d: Argument 1 of Illuminate\Support\Collection::first expects callable[impure](CfcpAnimal, int):bool|null, but parent type Closure[pure](CfcpDog):true provided
MixedArgumentTypeCoercion on line %d: Argument 1 of Illuminate\Support\Collection::reject expects CfcpAnimal|bool|callable[impure](CfcpAnimal, int):bool, but parent type callable[impure] provided
ArgumentTypeCoercion on line %d: Type CfcpAnimal should be a subtype of CfcpDog
ArgumentTypeCoercion on line %d: Argument 1 of Illuminate\Support\Collection::filter expects callable[impure](CfcpAnimal, int):bool|null, but parent type Closure[pure](CfcpDog...):true provided
ArgumentTypeCoercion on line %d: Type CfcpAnimal should be a subtype of CfcpDog
ArgumentTypeCoercion on line %d: Argument 1 of Illuminate\Support\Collection::filter expects callable[impure](CfcpAnimal, int):bool|null, but parent type Closure[impure](CfcpDog):bool provided
ArgumentTypeCoercion on line %d: Type CfcpAnimal should be a subtype of CfcpDog
ArgumentTypeCoercion on line %d: Argument 1 of CfcpBag::filter expects callable[impure](CfcpAnimal, int):bool|null, but parent type Closure[pure](CfcpDog):true provided
ArgumentTypeCoercion on line %d: Type CfcpAnimal should be a subtype of CfcpDog
ArgumentTypeCoercion on line %d: Argument 1 of Illuminate\Support\Collection::filter expects callable[impure](CfcpAnimal, int):bool|null, but parent type Closure[pure](CfcpDog):true provided
ArgumentTypeCoercion on line %d: Type CfcpAnimal should be a subtype of CfcpDog
ArgumentTypeCoercion on line %d: Argument 1 of Illuminate\Support\Collection::filter expects callable[impure](CfcpAnimal, int):bool|null, but parent type Closure[pure](CfcpDog):true provided
ArgumentTypeCoercion on line %d: Type CfcpAnimal should be a subtype of CfcpDog
ArgumentTypeCoercion on line %d: Argument 1 of Illuminate\Support\Collection::filter expects callable[impure](CfcpAnimal, int):bool|null, but parent type Closure[pure](CfcpDog):true provided
InvalidArgument on line %d: Argument 1 of Illuminate\Database\Eloquent\Collection::filter expects callable[impure](Illuminate\Database\Eloquent\Model, int):bool|null, but Closure[pure](CfcpDog):true provided
MixedArgumentTypeCoercion on line %d: Type mixed should be a subtype of parent
MixedArgumentTypeCoercion on line %d: Type mixed should be a subtype of CollectionFilterCallbackParamTest
ArgumentTypeCoercion on line %d: Type Illuminate\Database\Eloquent\Model should be a subtype of CfcpModel
ArgumentTypeCoercion on line %d: Argument 1 of Illuminate\Database\Eloquent\Collection::reject expects Illuminate\Database\Eloquent\Model|bool|callable[impure](Illuminate\Database\Eloquent\Model, int):bool, but parent type Closure[pure](CfcpModel):false provided
