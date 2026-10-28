--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Contracts\Database\Query\Expression;

/**
 * RelationCallbackParamsHandler declines (the stub's bare `\Closure` stands) when it cannot know the callback's
 * builder, instead of guessing base `Builder<Child>`:
 *  - the related model overrides `newEloquentBuilder()` with a docblock-only return type, which the plugin's
 *    detector does not register (it needs a native named return type), so the real builder is unknown;
 *  - the builder overrides a method the whereHas() family delegates to (`has()` here), which intercepts the callback.
 * The related models below are inline, so they are unregistered and would resolve to base `Builder`, which is the bug.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/2080
 */

/**
 * @template TModel of Model
 * @extends Builder<TModel>
 */
final class SpecialBuilder extends Builder
{
    /** @return $this */
    public function special(): static
    {
        return $this;
    }
}

final class OverriddenBuilderChild extends Model
{
    /**
     * @param \Illuminate\Database\Query\Builder $query
     * @return SpecialBuilder<static>
     */
    #[\Override]
    public function newEloquentBuilder($query)
    {
        /** @var SpecialBuilder<OverriddenBuilderChild> $builder */
        $builder = new SpecialBuilder($query);

        return $builder;
    }
}

final class OverriddenBuilderOwner extends Model
{
    /** @return HasMany<OverriddenBuilderChild, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(OverriddenBuilderChild::class);
    }

    /** @return MorphTo<Model, $this> */
    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }
}

function test_docblock_only_override_declines(): void
{
    OverriddenBuilderOwner::query()->whereHas('children', fn(SpecialBuilder $q): SpecialBuilder => $q->special());
}

function test_docblock_only_override_declines_morph(): void
{
    OverriddenBuilderOwner::query()->whereHasMorph(
        'reportable',
        [OverriddenBuilderChild::class],
        fn(SpecialBuilder $q, string $type): SpecialBuilder => $q->special()->where('type', $type),
    );
}

final class InterfaceBuilderChild extends Model
{
    #[\Override]
    public function newEloquentBuilder($query): BuilderContract
    {
        /** @var SpecialBuilder<InterfaceBuilderChild> $builder */
        $builder = new SpecialBuilder($query);

        return $builder;
    }
}

final class InterfaceBuilderOwner extends Model
{
    /** @return HasMany<InterfaceBuilderChild, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(InterfaceBuilderChild::class);
    }
}

/** A native interface return is not a registered builder (it is no Builder subclass): the slot builder is unknown. */
function test_interface_typed_override_declines(): void
{
    InterfaceBuilderOwner::query()->whereHas('children', fn(SpecialBuilder $q): SpecialBuilder => $q->special());
}

/** A native `: Builder` return with a docblock naming the real builder is still an unknown builder. */
final class NativeBaseDocblockChild extends Model
{
    /**
     * @param \Illuminate\Database\Query\Builder $query
     * @return SpecialBuilder<static>
     */
    #[\Override]
    public function newEloquentBuilder($query): Builder
    {
        /** @var SpecialBuilder<NativeBaseDocblockChild> $builder */
        $builder = new SpecialBuilder($query);

        return $builder;
    }
}

final class NativeBaseDocblockOwner extends Model
{
    /** @return HasMany<NativeBaseDocblockChild, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(NativeBaseDocblockChild::class);
    }
}

function test_native_base_builder_with_docblock_builder_declines(): void
{
    NativeBaseDocblockOwner::query()->whereHas('children', fn(SpecialBuilder $q): SpecialBuilder => $q->special());
}

/** A nullable native `: ?Builder` is still the base builder, so the callback stays typed. */
final class NullableBaseChild extends Model
{
    /** @param \Illuminate\Database\Query\Builder $query */
    #[\Override]
    public function newEloquentBuilder($query): ?Builder
    {
        return null;
    }
}

final class NullableBaseOwner extends Model
{
    /** @return HasMany<NullableBaseChild, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(NullableBaseChild::class);
    }
}

function test_nullable_native_base_builder_stays_typed(): void
{
    NullableBaseOwner::query()->whereHas('children', function ($q): void {
        /** @psalm-check-type-exact $q = Builder<NullableBaseChild> */
        $q->where('id', 1);
    });
}

/** A native `Builder&Marker` intersection is no plain base builder either. */
interface BuilderMarker {}

final class IntersectionBuilderChild extends Model
{
    /** @param \Illuminate\Database\Query\Builder $query */
    #[\Override]
    public function newEloquentBuilder($query): Builder&BuilderMarker
    {
        throw new \LogicException();
    }
}

final class IntersectionBuilderOwner extends Model
{
    /** @return HasMany<IntersectionBuilderChild, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(IntersectionBuilderChild::class);
    }
}

function test_intersection_builder_declines(): void
{
    IntersectionBuilderOwner::query()->whereHas('children', fn(Builder&BuilderMarker $q): Builder&BuilderMarker => $q);
}

class_alias(Builder::class, 'BaseBuilderAlias');

/** A native return type naming an alias of Eloquent's Builder is the base builder, so the callback stays typed. */
final class AliasedBaseChild extends Model
{
    /** @param \Illuminate\Database\Query\Builder $query */
    #[\Override]
    public function newEloquentBuilder($query): \BaseBuilderAlias
    {
        return new Builder($query);
    }
}

final class AliasedBaseOwner extends Model
{
    /** @return HasMany<AliasedBaseChild, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(AliasedBaseChild::class);
    }
}

function test_aliased_base_builder_stays_typed(): void
{
    AliasedBaseOwner::query()->whereHas('children', function ($q): void {
        /** @psalm-check-type-exact $q = Builder<AliasedBaseChild> */
        $q->where('id', 1);
    });
}

final class PlainRelated extends Model {}

final class PlainOwner extends Model
{
    /** @return HasMany<PlainRelated, $this> */
    public function rel(): HasMany
    {
        return $this->hasMany(PlainRelated::class);
    }

    /** @return MorphTo<Model, $this> */
    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }
}

/**
 * A builder overriding has() intercepts the callback of every method that delegates to it.
 *
 * @template TModel of Model
 * @extends Builder<TModel>
 */
final class HasOverridingBuilder extends Builder
{
    /**
     * @param Relation<Model, Model, mixed>|string $relation
     * @return $this
     */
    #[\Override]
    public function has($relation, string $operator = '>=', Expression|int $count = 1, string $boolean = 'and', ?\Closure $callback = null)
    {
        parent::has($relation, $operator, $count, $boolean, $callback);

        return $this;
    }
}

/** @param HasOverridingBuilder<PlainOwner> $builder */
function test_delegate_override_declines(HasOverridingBuilder $builder): void
{
    $builder->whereHas('rel', function ($q): void {
        /** @psalm-check-type-exact $q = Builder<Model> */
        $q->where('id', 1);
    });
}

/**
 * Past `hasMorph()` Laravel runs `whereHas()` on the OWNER model's builder, so a builder that is not the owner's
 * and overrides `whereHas()` does not intercept a morph call.
 *
 * @template TModel of Model
 * @extends Builder<TModel>
 */
final class WhereHasOverridingBuilder extends Builder
{
    /**
     * @param Relation<Model, Model, mixed>|string $relation
     * @return $this
     */
    #[\Override]
    public function whereHas($relation, ?\Closure $callback = null, string $operator = '>=', Expression|int $count = 1)
    {
        parent::whereHas($relation, $callback, $operator, $count);

        return $this;
    }
}

/** @param WhereHasOverridingBuilder<PlainOwner> $builder */
function test_external_builder_overriding_where_has_keeps_morph_call_typed(WhereHasOverridingBuilder $builder): void
{
    $builder->whereHasMorph('reportable', [PlainRelated::class], function ($q, $type): void {
        /** @psalm-check-type-exact $q = Builder<PlainRelated> */
        $q->where('type', $type);
    });
}

final class UnknownBuilderOwner extends Model
{
    /** @return HasMany<PlainRelated, $this> */
    public function rel(): HasMany
    {
        return $this->hasMany(PlainRelated::class);
    }

    /**
     * @param \Illuminate\Database\Query\Builder $query
     * @return HasOverridingBuilder<static>
     */
    #[\Override]
    public function newEloquentBuilder($query)
    {
        /** @var HasOverridingBuilder<UnknownBuilderOwner> $builder */
        $builder = new HasOverridingBuilder($query);

        return $builder;
    }
}

/**
 * A static call runs on the OWNER's own builder. Its docblock-only override is unknown to the plugin, so the
 * delegate overrides cannot be checked there.
 */
function test_unknown_owner_builder_declines_static_call(): void
{
    UnknownBuilderOwner::whereHas('rel', function (HasOverridingBuilder $q): void {
        $q->where('id', 1);
    });
}

/** Negative control: a related model without a newEloquentBuilder() override stays typed. */
function test_plain_related_model_stays_typed(): void
{
    PlainOwner::query()->whereHas('rel', function ($q): void {
        /** @psalm-check-type-exact $q = Builder<PlainRelated> */
        $q->where('id', 1);
    });
}
?>
--EXPECTF--
