--FILE--
<?php declare(strict_types=1);

use App\Builders\PostBuilder;
use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Tests that models using #[UseEloquentBuilder] attribute (Laravel 12+)
 * return the custom builder type instead of base Eloquent\Builder.
 *
 * @see https://laravel-news.com/defining-a-dedicated-query-builder-in-laravel-12-with-php-attributes
 */

/** Post::query() returns the custom builder, not base Builder. */
function test_query_returns_custom_builder(): void
{
    $_result = Post::query();
    /** @psalm-check-type-exact $_result = PostBuilder<Post> */
}

/** Custom builder methods are accessible via query(). */
function test_custom_method_via_query(): void
{
    $_result = Post::query()->wherePublished();
    /** @psalm-check-type-exact $_result = PostBuilder<Post> */
}

/** Chaining custom builder method to terminal get(). */
function test_custom_method_chain_to_get(): void
{
    $_result = Post::query()->wherePublished()->get();
    /** @psalm-check-type-exact $_result = Collection<int, Post> */
}

/** Multiple custom builder methods can be chained. */
function test_chain_multiple_custom_methods(): void
{
    $_result = Post::query()->wherePublished()->whereDraft();
    /** @psalm-check-type-exact $_result = PostBuilder<Post> */
}

/**
 * Base Builder methods still work on the custom builder.
 *
 * Returns Builder<Post> rather than PostBuilder<Post> because the Builder stub's
 * where() uses @return self<TModel> and self resolves to Builder (the declaring class).
 * Custom builder methods that explicitly return self<TModel> preserve the PostBuilder type.
 */
function test_base_builder_methods_still_work(): void
{
    $_result = Post::query()->where('title', 'Hello');
    /** @psalm-check-type-exact $_result = Builder<Post> */
}

/** Custom builder methods accessible via static call on the model. */
function test_custom_method_via_static_call(): void
{
    $_result = Post::wherePublished();
    /** @psalm-check-type-exact $_result = PostBuilder<Post> */
}

/** Static call chain: custom method -> get(). */
function test_static_custom_method_chain_to_get(): void
{
    $_result = Post::wherePublished()->get();
    /** @psalm-check-type-exact $_result = Collection<int, Post> */
}

/** Regression: standard query methods still work via static call. */
function test_static_where_still_works(): void
{
    $_result = Post::where('title', 'Hello')->get();
    /** @psalm-check-type-exact $_result = Collection<int, Post> */
}

/** Terminal method first() preserves model type through custom builder. */
function test_first_via_custom_builder(): void
{
    $_result = Post::query()->wherePublished()->first();
    /** @psalm-check-type-exact $_result = Post|null */
}

/** Terminal method find() preserves model type through custom builder. */
function test_find_via_custom_builder(): void
{
    $_result = Post::query()->wherePublished()->find(1);
    /** @psalm-check-type-exact $_result = Post|null */
}

/** Query\Builder-only method (whereIn) works via static call on custom builder model. */
function test_query_builder_method_via_static_call(): void
{
    $_result = Post::whereIn('id', [1, 2, 3]);
    /** @psalm-check-type-exact $_result = PostBuilder<Post>&static */
}

/** Negative test: nonexistent methods must still be reported. */
function test_nonexistent_method_on_custom_builder_model(): void
{
    $_result = Post::completelyFakeMethod();
}
?>
--EXPECTF--
UndefinedMagicMethod on line %d: Magic method App\Models\Post::completelyfakemethod does not exist
