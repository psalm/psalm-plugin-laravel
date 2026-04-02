--FILE--
<?php declare(strict_types=1);

use App\Models\Phone;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/593
 *
 * Verify that relationship methods do NOT return mixed.
 * Psalm doesn't support $this in generics (vimeo/psalm#11768), so our stubs
 * use `static` instead. This test ensures the return types resolve to their
 * relation class (not mixed), preventing MixedReturnStatement errors.
 */

// --- Without explicit @psalm-return annotations (Vault model) ---
// These are the core regression tests for #593: plain relationship calls
// must not produce MixedReturnStatement.

function test_belongsTo_without_annotation(Vault $vault): BelongsTo
{
    return $vault->owner();
}

function test_hasOne_without_annotation(Vault $vault): HasOne
{
    return $vault->latestPhone();
}

function test_morphOne_without_annotation(Vault $vault): MorphOne
{
    return $vault->featuredImage();
}

function test_morphTo_without_annotation(Vault $vault): MorphTo
{
    return $vault->vaultable();
}

function test_hasMany_without_annotation(Vault $vault): HasMany
{
    return $vault->posts();
}

function test_belongsToMany_without_annotation(Vault $vault): BelongsToMany
{
    return $vault->tags();
}

function test_morphMany_without_annotation(Vault $vault): MorphMany
{
    return $vault->comments();
}

function test_hasManyThrough_without_annotation(Vault $vault): HasManyThrough
{
    return $vault->mechanics();
}

function test_morphToMany_without_annotation(Vault $vault): MorphToMany
{
    return $vault->allTags();
}

function test_hasOneThrough_without_annotation(Vault $vault): HasOneThrough
{
    return $vault->carOwner();
}

function test_morphedByMany_without_annotation(Vault $vault): MorphToMany
{
    return $vault->morphedPosts();
}

// --- With explicit @psalm-return annotations (User model) ---

function test_hasOne_with_annotation(User $user): HasOne
{
    return $user->phone();
}

function test_belongsToMany_with_annotation(User $user): BelongsToMany
{
    return $user->roles();
}

function test_hasManyThrough_with_annotation(User $user): HasManyThrough
{
    return $user->carsAtMechanic();
}

// --- getRelated()/getParent() resolve correctly with explicit annotation ---

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
