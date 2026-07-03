--FILE--
<?php declare(strict_types=1);

use App\Builders\ArtistBuilder;
use App\Builders\FavoriteableBuilder;
use App\Models\Artist;
use Illuminate\Database\Eloquent\Model;

/**
 * Issue #1216 — custom builder SUBCLASS methods lost through a fluent chain.
 *
 * Artist opts into a two-level custom builder (ArtistBuilder extends FavoriteableBuilder<Artist>
 * extends Builder) via #[UseEloquentBuilder]. The abstract parent declares `accessible(): static`
 * (native `static`, no docblock `@return`). Reached through a fluent chain, the concrete
 * ArtistBuilder must NOT collapse to its abstract generic parent, or subclass methods surface as
 * false UndefinedMagicMethod.
 *
 * Root cause is an upstream Psalm TypeExpander limitation (it drops is_static when rebuilding a
 * native `: static` return on a generic class into a TGenericObject). BuilderNativeStaticReturnTypeHandler
 * compensates by rewriting the native `: static` into the docblock `@return static` form.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1216
 */

/** Baseline: #[UseEloquentBuilder] resolves the model's custom builder. */
function step0_query(): void
{
    $_q = Artist::query();
    /** @psalm-check-type-exact $_q = ArtistBuilder */
}

/** Crux: `accessible(): static` keeps the concrete ArtistBuilder, not the abstract parent. */
function step1_accessible(): void
{
    $_a = Artist::query()->accessible();
    /** @psalm-check-type-exact $_a = ArtistBuilder&static */
}

/** Through Conditionable::when(): still the concrete ArtistBuilder. */
function step2_accessible_when(): void
{
    $_w = Artist::query()->accessible()->when(true, static fn (ArtistBuilder $q): ArtistBuilder => $q);
    /** @psalm-check-type-exact $_w = ArtistBuilder&static */
}

/** Symptom regression: a PUBLIC subclass method at the end of the chain resolves (no UndefinedMagicMethod). */
function step3_subclass_method(): void
{
    $_r = Artist::query()
        ->accessible()
        ->when(true, static fn (ArtistBuilder $q): ArtistBuilder => $q)
        ->withPublicRating();
    /** @psalm-check-type-exact $_r = ArtistBuilder */
}

/**
 * Guard: calling the native-static method on the declaring generic builder itself preserves the
 * receiver's template argument (does not reset it to the base Model bound).
 *
 * @param FavoriteableBuilder<Artist> $b
 */
function guard_declaring_generic_receiver(FavoriteableBuilder $b): void
{
    $_g = $b->accessible();
    /** @psalm-check-type-exact $_g = FavoriteableBuilder<Artist>&static */
}

/** Guard: a nullable native `?static` return keeps its null atom while the static atom rewrites. */
function guard_nullable_native_static(): void
{
    $_n = Artist::query()->accessibleOrNull();
    /** @psalm-check-type-exact $_n = ArtistBuilder&static|null */
}

/** Guard: a native `: self` return is NEVER rewritten to `static` — it stays the declaring parent. */
function guard_self_not_rewritten(): void
{
    $_s = Artist::query()->notLateStaticBound();
    /** @psalm-check-type-exact $_s = FavoriteableBuilder<Model> */
}
?>
--EXPECTF--
