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
function test_where_preserves_relation_type(): void
{
    /** @var HasOne<Phone, User> $relation */
    $result = $relation->where('active', true);
    /** @psalm-check-type-exact $result = HasOne<Phone, User> */
}

// QueryBuilder method (NOT in Builder stub) also preserves Relation type.
// orderBy is on QueryBuilder only — falls to __call on HasOne.
// The handler extracts template params from the calling expression.
function test_orderby_preserves_relation_type(): void
{
    /** @var HasOne<Phone, User> $relation */
    $result = $relation->orderBy('name');
    /** @psalm-check-type-exact $result = HasOne<Phone, User> */
}

// Chained: Builder method → QueryBuilder method
function test_chained_builder_and_querybuilder(): void
{
    /** @var HasOne<Phone, User> $relation */
    $step1 = $relation->where('x', 1);
    /** @psalm-check-type-exact $step1 = HasOne<Phone, User> */
    $step2 = $step1->orderBy('y');
    /** @psalm-check-type-exact $step2 = HasOne<Phone, User> */
}

// Methods declared directly on Relation (via stubs) still work.
function test_latest_preserves_relation_type(): void
{
    /** @var HasOne<Phone, User> $relation */
    $result = $relation->latest();
    /** @psalm-check-type-exact $result = HasOne<Phone, User> */
}

// first() returns TRelatedModel|null — NOT self-returning.
// The Decorated style must return null here, letting Psalm resolve via @mixin.
function test_first_returns_model_or_null(): void
{
    /** @var HasOne<Phone, User> $relation */
    $result = $relation->first();
    /** @psalm-check-type-exact $result = Phone|null */
}

// Non-fluent methods: get() returns Collection, not Relation.
function test_get_returns_collection(): void
{
    /** @var HasOne<Phone, User> $relation */
    $result = $relation->get();
    /** @psalm-check-type-exact $result = \Illuminate\Database\Eloquent\Collection<int, Phone> */
}

// sole() returns TRelatedModel — not self-returning.
function test_sole_returns_model(): void
{
    /** @var HasOne<Phone, User> $relation */
    $result = $relation->sole();
    /** @psalm-check-type-exact $result = Phone */
}
?>
--EXPECTF--
