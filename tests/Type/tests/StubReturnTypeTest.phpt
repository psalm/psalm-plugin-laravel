--FILE--
<?php declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Tests for stub return types and @psalm-variadic annotations.
 */

function test_e_returns_string(): string
{
    return e('<script>alert("xss")</script>');
}

function test_model_load_returns_this(): User
{
    return (new User())->load('posts');
}

function test_model_load_variadic(): User
{
    return (new User())->load('posts', 'comments');
}

function test_model_load_missing_returns_this(): User
{
    return (new User())->loadMissing('posts');
}

function test_model_load_count_returns_this(): User
{
    return (new User())->loadCount('posts');
}

function test_model_make_visible_returns_this(): User
{
    return (new User())->makeVisible('email');
}

function test_model_make_visible_variadic(): User
{
    return (new User())->makeVisible('email', 'phone');
}

function test_model_make_hidden_returns_this(): User
{
    return (new User())->makeHidden('email');
}

function test_model_make_hidden_variadic(): User
{
    return (new User())->makeHidden('email', 'phone');
}

function test_query_builder_add_select(QueryBuilder $builder): QueryBuilder
{
    return $builder->addSelect('id', 'name');
}

function test_query_builder_distinct(QueryBuilder $builder): QueryBuilder
{
    return $builder->distinct();
}

/**
 * @param BelongsToMany<User, User> $relation
 * @return BelongsToMany<User, User>
 */
function test_belongs_to_many_with_pivot(BelongsToMany $relation): BelongsToMany
{
    return $relation->withPivot('role', 'created_at');
}

/** @param Collection<int, User> $collection */
function test_collection_load(Collection $collection): Collection
{
    return $collection->load('posts');
}

/** @param Collection<int, User> $collection */
function test_collection_load_variadic(Collection $collection): Collection
{
    return $collection->load('posts', 'comments');
}

/** @param Collection<int, User> $collection */
function test_collection_load_with_callback(Collection $collection): Collection
{
    return $collection->load(['posts' => function (\Illuminate\Database\Eloquent\Relations\Relation $query) {
        $query->getBaseQuery();
    }]);
}

/** @return Builder<User> */
function test_builder_with_callback(): Builder
{
    return User::query()->with('posts', function (\Illuminate\Database\Eloquent\Relations\Relation $query) {
        $query->getBaseQuery();
    });
}
?>
--EXPECT--
