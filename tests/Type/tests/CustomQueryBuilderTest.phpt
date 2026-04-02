--FILE--
<?php declare(strict_types=1);

use App\Builders\CarBuilder;
use App\Builders\MechanicBuilder;
use App\Builders\PostBuilder;
use App\Models\Car;
use App\Models\Mechanic;
use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Tests that models with custom query builders return the correct builder type
 * instead of base Eloquent\Builder.
 *
 * Three detection patterns are tested:
 * 1. #[UseEloquentBuilder] attribute (Laravel 12+) — Post model
 * 2. newEloquentBuilder() override with native return type — Car model
 * 3. protected static string $builder property override (all Laravel versions) — Mechanic model
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

/**
 * Query\Builder-only method (whereIn) works via static call on custom builder model.
 *
 * Returns PostBuilder<Post>&static (with &static) because this goes through the
 * __callStatic → executeFakeCall proxy path, which preserves the static intersection.
 * Custom builder methods (wherePublished) go through getReturnTypeForForwardedMethod
 * which returns PostBuilder<Post> without &static.
 */
function test_query_builder_method_via_static_call(): void
{
    $_result = Post::whereIn('id', [1, 2, 3]);
    /** @psalm-check-type-exact $_result = PostBuilder<Post>&static */
}

/** Custom method with parameters — exercises the getMethodParams provider path. */
function test_custom_method_with_params(): void
{
    $_result = Post::query()->whereAuthor(42);
    /** @psalm-check-type-exact $_result = PostBuilder<Post> */
}

/** Custom method with params via static call. */
function test_custom_method_with_params_static(): void
{
    $_result = Post::whereAuthor(42);
    /** @psalm-check-type-exact $_result = PostBuilder<Post> */
}

/** Legacy scope on model with custom builder returns PostBuilder<Post> via static call. */
function test_scope_on_custom_builder_model(): void
{
    $_result = Post::featured();
    /** @psalm-check-type-exact $_result = PostBuilder<Post> */
}

/** Legacy scope on model with custom builder via builder instance. */
function test_scope_on_custom_builder_via_query(): void
{
    $_result = Post::query()->featured();
    /** @psalm-check-type-exact $_result = PostBuilder<Post> */
}

/** Scope chained with a custom builder method. */
function test_scope_chain_with_custom_method(): void
{
    $_result = Post::query()->featured()->wherePublished();
    /** @psalm-check-type-exact $_result = PostBuilder<Post> */
}

/** Scope chained to terminal get(). */
function test_scope_chain_to_get(): void
{
    $_result = Post::query()->featured()->get();
    /** @psalm-check-type-exact $_result = Collection<int, Post> */
}

/** Modern #[Scope] attribute on model with custom builder via builder instance. */
function test_scope_attribute_on_custom_builder_via_query(): void
{
    $_result = Post::query()->popular();
    /** @psalm-check-type-exact $_result = PostBuilder<Post> */
}

/**
 * Known limitation: #[Scope] methods work at runtime via __callStatic → callNamedScope,
 * but Psalm sees them as real instance methods and reports InvalidStaticInvocation.
 * Same behavior as User::verified() in ModelStaticBuilderMethodsTest.
 */
function test_scope_attribute_static_is_invalid_on_custom_builder(): void
{
    $_result = Post::popular();
}

/** Scope with parameters via builder instance — exercises getScopeParams path. */
function test_scope_with_params_on_custom_builder_via_query(): void
{
    $_result = Post::query()->byCategory('tech');
    /** @psalm-check-type-exact $_result = PostBuilder<Post> */
}

/** Negative test: nonexistent methods on builder instance must still be reported. */
function test_nonexistent_method_on_custom_builder_instance(): void
{
    $_result = Post::query()->completelyFakeMethod();
}

// -----------------------------------------------------------------------
// SoftDeletes trait methods on custom builder
// Post uses both #[UseEloquentBuilder(PostBuilder::class)] and SoftDeletes.
// The @method static annotations on SoftDeletes (withTrashed, onlyTrashed,
// withoutTrashed) must return PostBuilder<Post>, not base Builder<Post>.
// See https://github.com/psalm/psalm-plugin-laravel/issues/631
// -----------------------------------------------------------------------

/** Static call: trait-declared builder method returns custom builder. */
function test_soft_deletes_with_trashed_static(): void
{
    $_result = Post::withTrashed();
    /** @psalm-check-type-exact $_result = PostBuilder<Post> */
}

/** Static call: onlyTrashed also returns custom builder. */
function test_soft_deletes_only_trashed_static(): void
{
    $_result = Post::onlyTrashed();
    /** @psalm-check-type-exact $_result = PostBuilder<Post> */
}

/** Static call: withoutTrashed also returns custom builder. */
function test_soft_deletes_without_trashed_static(): void
{
    $_result = Post::withoutTrashed();
    /** @psalm-check-type-exact $_result = PostBuilder<Post> */
}

/** Builder instance call: withTrashed on custom builder. */
function test_soft_deletes_with_trashed_via_query(): void
{
    $_result = Post::query()->withTrashed();
    /** @psalm-check-type-exact $_result = PostBuilder<Post> */
}

/** Builder instance call: onlyTrashed on custom builder. */
function test_soft_deletes_only_trashed_via_query(): void
{
    $_result = Post::query()->onlyTrashed();
    /** @psalm-check-type-exact $_result = PostBuilder<Post> */
}

/** Builder instance call: chaining trait method with custom builder method. */
function test_soft_deletes_chain_with_custom_method(): void
{
    $_result = Post::query()->withTrashed()->wherePublished();
    /** @psalm-check-type-exact $_result = PostBuilder<Post> */
}

/** Builder instance call: chaining trait method to terminal get(). */
function test_soft_deletes_chain_to_get(): void
{
    $_result = Post::query()->withTrashed()->get();
    /** @psalm-check-type-exact $_result = Collection<int, Post> */
}

/**
 * restoreOrCreate returns the model type (static), not the builder — must NOT be remapped.
 * The &static intersection comes from Psalm's native @method static resolution.
 */
function test_soft_deletes_restore_or_create_returns_model(): void
{
    $_result = Post::restoreOrCreate(['slug' => 'test']);
    /** @psalm-check-type-exact $_result = Post&static */
}

/** createOrRestore also returns the model type, not the builder. */
function test_soft_deletes_create_or_restore_returns_model(): void
{
    $_result = Post::createOrRestore(['slug' => 'test']);
    /** @psalm-check-type-exact $_result = Post&static */
}

// -----------------------------------------------------------------------
// newEloquentBuilder() override pattern (pre-Laravel 12)
// Car model overrides newEloquentBuilder() with a native return type.
// -----------------------------------------------------------------------

/** Car::query() returns the custom builder via newEloquentBuilder() override. */
function test_new_eloquent_builder_query(): void
{
    $_result = Car::query();
    /** @psalm-check-type-exact $_result = CarBuilder<Car> */
}

/** Custom builder methods work via query() on newEloquentBuilder model. */
function test_new_eloquent_builder_custom_method(): void
{
    $_result = Car::query()->whereElectric();
    /** @psalm-check-type-exact $_result = CarBuilder<Car> */
}

/** Custom builder methods work via static call on newEloquentBuilder model. */
function test_new_eloquent_builder_static_call(): void
{
    $_result = Car::whereElectric();
    /** @psalm-check-type-exact $_result = CarBuilder<Car> */
}

/** Terminal method through newEloquentBuilder custom builder. */
function test_new_eloquent_builder_terminal(): void
{
    $_result = Car::query()->whereElectric()->get();
    /** @psalm-check-type-exact $_result = Collection<int, Car> */
}

/** Scope on newEloquentBuilder model via builder instance. */
function test_scope_on_new_eloquent_builder_via_query(): void
{
    $_result = Car::query()->available();
    /** @psalm-check-type-exact $_result = CarBuilder<Car> */
}

// -----------------------------------------------------------------------
// static $builder property pattern (all Laravel versions)
// Mechanic model sets protected static string $builder = MechanicBuilder::class.
// -----------------------------------------------------------------------

/** Mechanic::query() returns the custom builder via static $builder property. */
function test_static_builder_property_query(): void
{
    $_result = Mechanic::query();
    /** @psalm-check-type-exact $_result = MechanicBuilder<Mechanic> */
}

/** Custom builder methods work via query() on static $builder model. */
function test_static_builder_property_custom_method(): void
{
    $_result = Mechanic::query()->whereCertified();
    /** @psalm-check-type-exact $_result = MechanicBuilder<Mechanic> */
}

/** Custom builder methods work via static call on static $builder model. */
function test_static_builder_property_static_call(): void
{
    $_result = Mechanic::whereCertified();
    /** @psalm-check-type-exact $_result = MechanicBuilder<Mechanic> */
}

/** Scope on static $builder property model via builder instance. */
function test_scope_on_static_builder_property_via_query(): void
{
    $_result = Mechanic::query()->experienced();
    /** @psalm-check-type-exact $_result = MechanicBuilder<Mechanic> */
}

/** Negative test: nonexistent methods must still be reported. */
function test_nonexistent_method_on_custom_builder_model(): void
{
    $_result = Post::completelyFakeMethod();
}
?>
--EXPECTF--
InvalidStaticInvocation on line %d: Method App\Models\Post::popular is not static, but is called statically
UndefinedMagicMethod on line %d: Magic method App\Builders\PostBuilder::completelyfakemethod does not exist
UndefinedMagicMethod on line %d: Magic method App\Models\Post::completelyfakemethod does not exist
