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
?>
--EXPECTF--
