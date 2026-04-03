--FILE--
<?php declare(strict_types=1);

use App\Models\User;
use App\Models\Phone;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Verify that the unified MethodForwardingHandler with interceptMixin=true
 * correctly resolves return types for ALL methods on Relations — including
 * methods resolved via @mixin AND methods resolved via __call.
 */

// Builder method (in Builder stub) preserves Relation type via mixin interception.
function test_where_preserves_relation_type(HasOne $relation): HasOne
{
    /** @var HasOne<Phone, User> $relation */
    return $relation->where('active', true);
}

// QueryBuilder method (NOT in Builder stub) also preserves Relation type.
// orderBy is on QueryBuilder only — falls to __call on HasOne.
// The handler extracts template params from the calling expression.
function test_orderby_preserves_relation_type(HasOne $relation): HasOne
{
    /** @var HasOne<Phone, User> $relation */
    return $relation->orderBy('name');
}

// Chained: Builder method → QueryBuilder method
function test_chained_builder_and_querybuilder(HasOne $relation): HasOne
{
    /** @var HasOne<Phone, User> $relation */
    $step1 = $relation->where('x', 1);
    return $step1->orderBy('y');
}

// Methods declared directly on Relation (via stubs) still work.
function test_latest_preserves_relation_type(HasOne $relation): HasOne
{
    /** @var HasOne<Phone, User> $relation */
    return $relation->latest();
}

// Non-fluent methods: get() returns Collection, not Relation.
/** @return \Illuminate\Database\Eloquent\Collection<int, Phone> */
function test_get_returns_collection(HasOne $relation): \Illuminate\Database\Eloquent\Collection
{
    /** @var HasOne<Phone, User> $relation */
    return $relation->get();
}

// sole() returns TRelatedModel — not self-returning.
function test_sole_returns_model(HasOne $relation): Phone
{
    /** @var HasOne<Phone, User> $relation */
    return $relation->sole();
}
?>
--EXPECTF--
