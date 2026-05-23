--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection;

/**
 * Pins the accepted soundness trade-off in `BuilderFindMixedHandler` (#975).
 *
 * When the caller's `$id` is typed `mixed` but at runtime happens to be an
 * `array` or `Arrayable`, Laravel returns a `Collection<int, TModel>`. The
 * handler still narrows the static type to `TModel|null`, so subsequent
 * property access on what is actually a Collection passes Psalm but fails at
 * runtime. This is the same trade-off Larastan accepts via its `@method`
 * overload pair; it is preferable to keeping the wider `Collection|TModel|null`
 * union because that wider union breaks the vastly more common case (DB-row
 * id lookup, e.g., `\DB::select()` returning `list<\stdClass>` whose property
 * reads are `mixed` even though every value is scalar).
 *
 * If this test ever needs updating because the handler is refined to detect
 * the mixed-but-array case (e.g., by inspecting the source expression for an
 * obvious array narrowing), update both the assertion below and the inline
 * docblock on `BuilderFindMixedHandler` to reflect the new behavior.
 */
function trade_off_mixed_that_is_actually_array(array $ids): void
{
    /** @var mixed $erased At runtime $erased is `array<int>`; declared as mixed to mimic stdClass property reads. */
    $erased = $ids;

    // Customer::find($erased) at runtime returns Collection<int, Customer>,
    // not Customer|null. The handler reports the scalar branch anyway —
    // that's the accepted trade-off. Pinning the inferred type makes the
    // soundness gap visible to anyone reading these tests.
    $_r = Customer::find($erased);
    /** @psalm-check-type-exact $_r = Customer&static|null */
}

?>
--EXPECTF--
