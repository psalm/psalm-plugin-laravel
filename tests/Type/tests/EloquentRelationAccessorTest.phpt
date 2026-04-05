--FILE--
<?php declare(strict_types=1);

use App\Collections\PostCollection;
use App\Collections\TagCollection;
use App\Models\Comment;
use App\Models\Image;
use App\Models\Mechanic;
use App\Models\Phone;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/497
 *
 * Test that relationship methods WITHOUT generic type annotations are
 * resolved as magic property accessors with correct types.
 */

// --- Single relations: ?RelatedModel ---

function test_belongsTo_without_generics(Vault $vault): ?User
{
    /** @psalm-check-type-exact $owner = User|null */
    $owner = $vault->owner;
    return $owner;
}

function test_hasOne_without_generics(Vault $vault): ?Phone
{
    /** @psalm-check-type-exact $phone = Phone|null */
    $phone = $vault->latestPhone;
    return $phone;
}

function test_morphOne_without_generics(Vault $vault): ?Image
{
    /** @psalm-check-type-exact $image = Image|null */
    $image = $vault->featuredImage;
    return $image;
}

function test_belongsTo_chained_withDefault(Vault $vault): ?User
{
    /** @psalm-check-type-exact $account = User|null */
    $account = $vault->account;
    return $account;
}

// --- Collection relations: custom collection types when registered (#645) ---

/** @return PostCollection<int, Post> */
function test_hasMany_custom_collection(Vault $vault): PostCollection
{
    /** @psalm-check-type-exact $posts = PostCollection<int, Post> */
    $posts = $vault->posts;
    return $posts;
}

/** @return TagCollection<int, Tag> */
function test_belongsToMany_custom_collection(Vault $vault): TagCollection
{
    /** @psalm-check-type-exact $tags = TagCollection<int, Tag> */
    $tags = $vault->tags;
    return $tags;
}

// Comment has no custom collection — still returns default Collection
/** @return Collection<int, Comment> */
function test_morphMany_without_generics(Vault $vault): Collection
{
    /** @psalm-check-type-exact $comments = Collection<int, Comment> */
    $comments = $vault->comments;
    return $comments;
}

// Mechanic has no custom collection — still returns default Collection
/** @return Collection<int, Mechanic> */
function test_hasManyThrough_without_generics(Vault $vault): Collection
{
    /** @psalm-check-type-exact $mechanics = Collection<int, Mechanic> */
    $mechanics = $vault->mechanics;
    return $mechanics;
}

/** @return TagCollection<int, Tag> */
function test_morphToMany_custom_collection(Vault $vault): TagCollection
{
    /** @psalm-check-type-exact $allTags = TagCollection<int, Tag> */
    $allTags = $vault->allTags;
    return $allTags;
}

function test_hasOneThrough_without_generics(Vault $vault): ?User
{
    /** @psalm-check-type-exact $carOwner = User|null */
    $carOwner = $vault->carOwner;
    return $carOwner;
}

/** @return PostCollection<int, Post> */
function test_morphedByMany_custom_collection(Vault $vault): PostCollection
{
    /** @psalm-check-type-exact $posts = PostCollection<int, Post> */
    $posts = $vault->morphedPosts;
    return $posts;
}

// --- MorphTo: ?Model (polymorphic, can't determine specific type) ---

function test_morphTo_without_generics(Vault $vault): ?Model
{
    /** @psalm-check-type-exact $vaultable = Model|null */
    $vaultable = $vault->vaultable;
    return $vaultable;
}

function test_morphTo_with_psalm_return_generics(Comment $comment): Post|User|null
{
    /** @psalm-check-type-exact $commentable = Post|User|null */
    $commentable = $comment->commentable;
    return $commentable;
}

function test_morphTo_with_return_generics(Image $image): Post|User|null
{
    /** @psalm-check-type-exact $imageable = Post|User|null */
    $imageable = $image->imageable;
    return $imageable;
}

// --- No declared return type: inferred from method body ---

function test_no_return_type_morphOne(User $user): ?Image
{
    /** @psalm-check-type-exact $image = Image|null */
    $image = $user->image;
    return $image;
}

// Image has no custom collection — default Collection
/** @return Collection<int, Image> */
function test_no_return_type_morphMany(Vault $vault): Collection
{
    /** @psalm-check-type-exact $images = Collection<int, Image> */
    $images = $vault->images;
    return $images;
}

// --- Existing behavior: generics still work ---

function test_belongsTo_with_generics(Phone $phone): ?User
{
    /** @psalm-check-type-exact $user = User|null */
    $user = $phone->user;
    return $user;
}

/** @return Collection<int, Comment> */
function test_hasMany_with_generics(Post $post): Collection
{
    /** @psalm-check-type-exact $comments = Collection<int, Comment> */
    $comments = $post->comments;
    return $comments;
}

// --- Generics + custom collection: Tag has $collectionClass ---

/** @return TagCollection<int, Tag> */
function test_morphToMany_with_generics_custom_collection(Post $post): TagCollection
{
    /** @psalm-check-type-exact $tags = TagCollection<int, Tag> */
    $tags = $post->tags;
    return $tags;
}
?>
--EXPECTF--
