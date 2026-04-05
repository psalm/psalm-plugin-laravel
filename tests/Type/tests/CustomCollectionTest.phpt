--FILE--
<?php declare(strict_types=1);

use App\Collections\PostCollection;
use App\Collections\SecretCollection;
use App\Collections\TagCollection;
use App\Collections\WorkOrderCollection;
use App\Models\Post;
use App\Models\Secret;
use App\Models\Tag;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Models with custom collections should return the custom collection type
 * from Builder methods, Relation methods, and Model::all().
 *
 * Three detection patterns are tested:
 * 1. #[CollectedBy] attribute (Post model, WorkOrder model)
 * 2. newCollection() override with native return type (Secret model)
 * 3. protected static string $collectionClass property (Tag model)
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/622
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/658
 */

// === #[CollectedBy] attribute (Post model) ===

// --- Builder::get() ---

/** @param Builder<Post> $builder */
function test_builder_get(Builder $builder): PostCollection
{
    /** @psalm-check-type-exact $result = PostCollection<int, Post> */
    $result = $builder->get();
    return $result;
}

// --- Builder::findMany() ---

/** @param Builder<Post> $builder */
function test_builder_findMany(Builder $builder): PostCollection
{
    /** @psalm-check-type-exact $result = PostCollection<int, Post> */
    $result = $builder->findMany([1, 2, 3]);
    return $result;
}

// --- Builder::hydrate() ---

/** @param Builder<Post> $builder */
function test_builder_hydrate(Builder $builder): PostCollection
{
    /** @psalm-check-type-exact $result = PostCollection<int, Post> */
    $result = $builder->hydrate([]);
    return $result;
}

// --- Builder::fromQuery() ---

/** @param Builder<Post> $builder */
function test_builder_fromQuery(Builder $builder): PostCollection
{
    /** @psalm-check-type-exact $result = PostCollection<int, Post> */
    $result = $builder->fromQuery('SELECT * FROM posts');
    return $result;
}

// --- Model::all() ---

function test_model_all(): PostCollection
{
    /** @psalm-check-type-exact $result = PostCollection<int, Post> */
    $result = Post::all();
    return $result;
}

// --- Static call forwarded to Builder: Model::where()->get() ---

function test_static_get(): PostCollection
{
    /** @psalm-check-type-exact $result = PostCollection<int, Post> */
    $result = Post::where('published', true)->get();
    return $result;
}

// --- Custom collection methods are available ---

/** @param Builder<Post> $builder */
function test_custom_method_available(Builder $builder): PostCollection
{
    $collection = $builder->get();
    /** @psalm-check-type-exact $published = PostCollection<int, Post>&static */
    $published = $collection->published();
    return $published;
}

// === newCollection() override (Secret model) ===

/** @param Builder<Secret> $builder */
function test_newCollection_builder_get(Builder $builder): SecretCollection
{
    /** @psalm-check-type-exact $result = SecretCollection<int, Secret> */
    $result = $builder->get();
    return $result;
}

function test_newCollection_model_all(): SecretCollection
{
    /** @psalm-check-type-exact $result = SecretCollection<int, Secret> */
    $result = Secret::all();
    return $result;
}

// === $collectionClass property (Tag model) ===

/** @param Builder<Tag> $builder */
function test_collectionClass_builder_get(Builder $builder): TagCollection
{
    /** @psalm-check-type-exact $result = TagCollection<int, Tag> */
    $result = $builder->get();
    return $result;
}

function test_collectionClass_model_all(): TagCollection
{
    /** @psalm-check-type-exact $result = TagCollection<int, Tag> */
    $result = Tag::all();
    return $result;
}

// === Relation method calls should also return custom collection (#658) ===
// Repair shop domain: Vehicle -> HasMany -> WorkOrder (WorkOrderCollection via #[CollectedBy])

// --- HasMany::get() on a model with custom collection ---

/** @param \Illuminate\Database\Eloquent\Relations\HasMany<WorkOrder, Vehicle> $relation */
function test_relation_get_custom_collection(\Illuminate\Database\Eloquent\Relations\HasMany $relation): WorkOrderCollection
{
    /** @psalm-check-type-exact $result = WorkOrderCollection<int, WorkOrder> */
    $result = $relation->get();
    return $result;
}

// --- BelongsToMany::get() on a model with custom collection ---

/** @param \Illuminate\Database\Eloquent\Relations\BelongsToMany<Tag, \App\Models\Vault> $relation */
function test_belongsToMany_get_custom_collection(\Illuminate\Database\Eloquent\Relations\BelongsToMany $relation): TagCollection
{
    /** @psalm-check-type-exact $result = TagCollection<int, Tag> */
    $result = $relation->get();
    return $result;
}

// --- BelongsToMany::findMany() on a model with custom collection ---

/** @param \Illuminate\Database\Eloquent\Relations\BelongsToMany<Tag, \App\Models\Vault> $relation */
function test_belongsToMany_findMany_custom_collection(\Illuminate\Database\Eloquent\Relations\BelongsToMany $relation): TagCollection
{
    /** @psalm-check-type-exact $result = TagCollection<int, Tag> */
    $result = $relation->findMany([1, 2, 3]);
    return $result;
}

// --- MorphToMany::get() on a model with custom collection ---

/** @param \Illuminate\Database\Eloquent\Relations\MorphToMany<Tag, \App\Models\Vault> $relation */
function test_morphToMany_get_custom_collection(\Illuminate\Database\Eloquent\Relations\MorphToMany $relation): TagCollection
{
    /** @psalm-check-type-exact $result = TagCollection<int, Tag> */
    $result = $relation->get();
    return $result;
}

// --- Relation::get() on model WITHOUT custom collection stays Collection ---

/** @param \Illuminate\Database\Eloquent\Relations\HasMany<Vehicle, User> $relation */
function test_relation_get_default_collection(\Illuminate\Database\Eloquent\Relations\HasMany $relation): Collection
{
    /** @psalm-check-type-exact $result = Collection<int, Vehicle> */
    $result = $relation->get();
    return $result;
}

// --- MorphTo::get() on a union related model must not narrow to a single custom collection ---

/** @param \Illuminate\Database\Eloquent\Relations\MorphTo<Post|User, Secret> $relation */
function test_morphTo_get_union_related_collection(\Illuminate\Database\Eloquent\Relations\MorphTo $relation): Collection
{
    /** @psalm-check-type-exact $result = Collection<int, Post|User> */
    $result = $relation->get();
    return $result;
}

// === Models WITHOUT custom collection still return Eloquent\Collection ===

/** @param Builder<User> $builder */
function test_default_collection(Builder $builder): Collection
{
    /** @psalm-check-type-exact $result = Collection<int, User> */
    $result = $builder->get();
    return $result;
}

function test_default_collection_all(): Collection
{
    /** @psalm-check-type-exact $result = Collection<int, User> */
    $result = User::all();
    return $result;
}
?>
--EXPECTF--
