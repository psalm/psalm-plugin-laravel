--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Stringable;

/**
 * Regression tests for Conditionable::when()/unless() and Tappable::tap().
 *
 * Without stubs these methods return mixed, breaking all fluent chains.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/704
 */

// --- Eloquent Builder ---

/**
 * when() on an Eloquent Builder must preserve the builder type, not return mixed.
 * Before the stub, ->where() after ->when() would produce MixedMethodCall.
 */
function test_eloquent_builder_when_fluent_chain(): void
{
    Customer::query()
        ->when(true, null, null)
        ->where('active', true)
        ->paginate();
}

/** unless() on an Eloquent Builder must preserve the builder type, not return mixed. */
function test_eloquent_builder_unless_fluent_chain(): void
{
    Customer::query()
        ->unless(false, null, null)
        ->where('active', true)
        ->paginate();
}

/**
 * when() on a typed Builder variable returns the same builder type ($this).
 *
 * @param Builder<Customer> $builder
 */
function test_eloquent_builder_when_return_type(Builder $builder): void
{
    $_result = $builder->when(true, null, null);
    /** @psalm-check-type-exact $_result = Builder<Customer>&static */
}

/**
 * unless() on a typed Builder variable returns the same builder type ($this).
 *
 * @param Builder<Customer> $builder
 */
function test_eloquent_builder_unless_return_type(Builder $builder): void
{
    $_result = $builder->unless(false, null, null);
    /** @psalm-check-type-exact $_result = Builder<Customer>&static */
}

// --- Query\Builder (via BuildsQueries trait) ---

/**
 * Query\Builder uses Conditionable via BuildsQueries — fluent chain must not break.
 * This validates that the trait stub propagates through the indirect inheritance path.
 */
function test_query_builder_when_fluent_chain(QueryBuilder $builder): void
{
    $builder
        ->when(true, null, null)
        ->where('active', true)
        ->get();
}

/** when() on Query\Builder returns the same builder type ($this). */
function test_query_builder_when_return_type(QueryBuilder $builder): void
{
    $_result = $builder->when(true, null, null);
    /** @psalm-check-type-exact $_result = Illuminate\Database\Query\Builder&static */
}

// --- Collection ---

/** when() on Collection must preserve the collection type, not return mixed. */
function test_collection_when_fluent_chain(): void
{
    /** @var Collection<int, string> $collection */
    $collection = new Collection(['a', 'b', 'c']);
    $_result = $collection->when(true, null, null);
    /** @psalm-check-type-exact $_result = Collection<int, string>&static */
    $_result->values();
}

// --- Stringable ---

/** when() on Stringable must preserve Stringable, not return mixed. */
function test_stringable_when_fluent_chain(): void
{
    (new Stringable('hello'))
        ->when(true, null, null)
        ->value();
}

// --- Tappable::tap() ---

/** tap() with a callback returns $this — preserves the calling type in the chain. */
function test_stringable_tap_with_callback(): void
{
    $_result = (new Stringable('hello'))->tap(static function (Stringable $s): void {
        echo (string) $s;
    });
    /** @psalm-check-type-exact $_result = Stringable&static */
}

/**
 * tap() without a callback also types as $this (stub simplification).
 *
 * At runtime Laravel returns HigherOrderTapProxy for the null case.
 * The stub approximates this as $this to avoid mixed collapse — acceptable
 * because HigherOrderTapProxy proxies all calls back to the original object.
 */
function test_tap_without_callback(): void
{
    $_result = (new Stringable('hello'))->tap();
    /** @psalm-check-type-exact $_result = Stringable&static */
}
?>
--EXPECT--
