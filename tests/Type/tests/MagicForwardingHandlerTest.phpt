--FILE--
<?php declare(strict_types=1);

use App\Models\User;
use App\Models\Phone;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Verify that the unified MethodForwardingHandler with interceptMixin=true
 * correctly resolves return types for Relation→Builder (Decorated forwarding).
 */

// Builder method preserves Relation type via mixin interception.
function test_where(): HasOne
{
    /** @var HasOne<Phone, User> $relation */
    return $relation->where('active', true);
}

// Methods declared directly on Relation (via stubs) still work.
function test_latest(): HasOne
{
    /** @var HasOne<Phone, User> $relation */
    return $relation->latest();
}

// Non-fluent methods: get() returns Collection, not Relation.
/** @return \Illuminate\Database\Eloquent\Collection<int, Phone> */
function test_get(): \Illuminate\Database\Eloquent\Collection
{
    /** @var HasOne<Phone, User> $relation */
    return $relation->get();
}

// sole() returns TRelatedModel — not self-returning.
function test_sole(): Phone
{
    /** @var HasOne<Phone, User> $relation */
    return $relation->sole();
}
?>
--EXPECTF--
