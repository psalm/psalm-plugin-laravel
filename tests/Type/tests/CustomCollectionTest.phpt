--FILE--
<?php declare(strict_types=1);

use App\Collections\PostCollection;
use App\Collections\SecretCollection;
use App\Models\Post;
use App\Models\Secret;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Models with #[CollectedBy] or newCollection() override should return
 * the custom collection type from Builder methods and Model::all().
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/622
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
