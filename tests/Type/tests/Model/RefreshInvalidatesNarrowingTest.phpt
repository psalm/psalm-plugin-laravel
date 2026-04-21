--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use App\Models\Customer;

/**
 * Methods that mass-assign or replace the underlying attributes array
 * (refresh, fill, forceFill, setRawAttributes, update) must invalidate
 * Psalm's per-name narrowings on @property-declared attributes of the
 * receiver.
 *
 * Without invalidation, a literal assignment like `$customer->id = 'x';`
 * would leave `$customer->id` typed as the literal `'x'` after a refresh(),
 * producing false RedundantCondition errors on subsequent reads.
 *
 * Customer declares `@property string $id` (overrides migration-inferred int).
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/818
 */

function test_refresh_invalidates_narrowing(Customer $customer): string
{
    $customer->id = 'specific-id';
    /** @psalm-check-type-exact $id_before = 'specific-id' */
    $id_before = $customer->id;

    $customer->refresh();
    $id_after = $customer->id;
    /** @psalm-check-type-exact $id_after = string */
    return $id_before . $id_after;
}

function test_fill_invalidates_narrowing(Customer $customer): string
{
    $customer->id = 'specific-id';
    $customer->fill(['id' => 'other']);
    $id = $customer->id;
    /** @psalm-check-type-exact $id = string */
    return $id;
}

function test_force_fill_invalidates_narrowing(Customer $customer): string
{
    $customer->id = 'specific-id';
    $customer->forceFill(['id' => 'other']);
    $id = $customer->id;
    /** @psalm-check-type-exact $id = string */
    return $id;
}

function test_set_raw_attributes_invalidates_narrowing(Customer $customer): string
{
    $customer->id = 'specific-id';
    $customer->setRawAttributes(['id' => 'other']);
    $id = $customer->id;
    /** @psalm-check-type-exact $id = string */
    return $id;
}

function test_update_invalidates_narrowing(Customer $customer): string
{
    $customer->id = 'specific-id';
    $customer->update(['id' => 'other']);
    $id = $customer->id;
    /** @psalm-check-type-exact $id = string */
    return $id;
}

/**
 * Sanity check: a non-mutating method call on the same model must NOT
 * invalidate the narrowing. Otherwise we'd be over-eager and wipe out
 * legitimate narrowings on every method call.
 */
function test_non_mutating_method_preserves_narrowing(Customer $customer): string
{
    $customer->id = 'specific-id';
    $customer->getKey();
    $id = $customer->id;
    /** @psalm-check-type-exact $id = 'specific-id' */
    return $id;
}

/**
 * A chained property fetch as the receiver — the originally reported idiom
 * in #818 (`$this->member->preference->refresh()`). Exercises the recursive
 * branch of the handler's var-id builder.
 */
final class ChainedReceiver
{
    public function __construct(public Customer $customer)
    {
    }

    public function test_chained_property_fetch_receiver(): string
    {
        $this->customer->id = 'specific-id';
        $this->customer->refresh();
        $id = $this->customer->id;
        /** @psalm-check-type-exact $id = string */
        return $id;
    }
}

?>
--EXPECTF--
