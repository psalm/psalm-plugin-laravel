--FILE--
<?php declare(strict_types=1);

use App\Models\User;
use App\Models\Phone;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Verify that relation stubs accept multi-param generics matching Laravel's native annotations.
 * HasOne<Related, Declaring>, HasManyThrough<Related, Intermediate, Declaring>, etc.
 */

function test_hasOne_returns_typed_relation(): HasOne
{
    return (new User())->phone();
}

function test_belongsToMany_returns_typed_relation(): BelongsToMany
{
    return (new User())->roles();
}

function test_hasManyThrough_returns_typed_relation(): HasManyThrough
{
    return (new User())->carsAtMechanic();
}

/**
 * When the generic type is explicitly annotated, getRelated()/getParent() resolve correctly.
 * Psalm cannot yet infer full generic params through trait methods, so @var is used to
 * verify template parameter propagation through the relation API.
 */
function test_hasOne_getRelated_returns_phone(): Phone
{
    /** @var HasOne<Phone, User> $relation */
    $relation = (new User())->phone();
    /** @psalm-check-type-exact $relation = HasOne<Phone, User> */
    return $relation->getRelated();
}

function test_hasOne_getParent_returns_user(): User
{
    /** @var HasOne<Phone, User> $relation */
    $relation = (new User())->phone();
    return $relation->getParent();
}
/**
 * Relation method return types: fluent methods return self, terminal methods return models/collections.
 */

function test_latest_preserves_relation_type(): HasOne
{
    /** @var HasOne<Phone, User> $relation */
    $relation = (new User())->phone();
    /** @psalm-check-type-exact $latest = HasOne<Phone, User> */
    $latest = $relation->latest();
    return $latest;
}

/** @return \Illuminate\Database\Eloquent\Collection<int, Phone> */
function test_get_returns_collection(): \Illuminate\Database\Eloquent\Collection
{
    /** @var HasOne<Phone, User> $relation */
    $relation = (new User())->phone();
    /** @psalm-check-type-exact $collection = \Illuminate\Database\Eloquent\Collection<int, Phone> */
    $collection = $relation->get();
    return $collection;
}

function test_sole_returns_model(): Phone
{
    /** @var HasOne<Phone, User> $relation */
    $relation = (new User())->phone();
    /** @psalm-check-type-exact $model = Phone */
    $model = $relation->sole();
    return $model;
}

/** @todo where() on Relation returns Builder instead of HasOne (needs mixin interception fix) */
// function test_where_preserves_relation_type(HasOne $relation): void
// {
//     /** @var HasOne<Phone, User> $relation */
//     /** @psalm-check-type-exact $result = HasOne<Phone, User> */
//     $result = $relation->where('active', true);
// }

/** @todo orderBy() on Relation loses type entirely (needs __call forwarding fix) */
// function test_orderby_preserves_relation_type(HasOne $relation): void
// {
//     /** @var HasOne<Phone, User> $relation */
//     /** @psalm-check-type-exact $result = HasOne<Phone, User> */
//     $result = $relation->orderBy('name');
// }

/** @todo chained Builder+QueryBuilder returns Builder instead of HasOne */
// function test_chained_builder_and_querybuilder(HasOne $relation): void
// {
//     /** @var HasOne<Phone, User> $relation */
//     $step1 = $relation->where('x', 1);
//     /** @psalm-check-type-exact $step1 = HasOne<Phone, User> */
// }
?>
--EXPECTF--
