--FILE--
<?php declare(strict_types=1);

use App\Models\User;
use App\Models\Phone;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Verify that the unified MethodForwardingHandler correctly resolves return
 * types for Relation→Builder (Decorated) forwarding.
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

// first() returns TRelatedModel|null — NOT self-returning.
function test_first(): ?Phone
{
    /** @var HasOne<Phone, User> $relation */
    return $relation->first();
}

// first() after fluent chain preserves the model type.
function test_chain_then_first(): ?Phone
{
    /** @var HasOne<Phone, User> $relation */
    return $relation->where('active', true)->orderBy('name')->first();
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
