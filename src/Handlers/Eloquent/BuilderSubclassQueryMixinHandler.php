<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Type\Atomic\TNamedObject;

/**
 * Restores the `@mixin Query\Builder` that Psalm drops on user Eloquent\Builder subclasses
 * which declare their own `@mixin` (typically `@mixin <Model>`).
 *
 * Eloquent\Builder forwards Query\Builder methods (whereNotNull, orWhereNull, orderBy, ...)
 * through a stub `@mixin \Illuminate\Database\Query\Builder`. A subclass normally inherits that
 * mixin, so those methods resolve on, e.g., a plain VehicleBuilder. But when the subclass adds
 * its own `@mixin Model` (a common pattern to expose model attributes/scopes on builder
 * instances), Psalm does NOT merge the child's mixins with the parent's — its populator
 * REPLACES the inherited list with the child's own (Populator::populateDataFromParentClass,
 * the `if (!$storage->namedMixins)` guard). The inherited `@mixin Query\Builder` is lost, so
 * forwarded methods surface as false UndefinedMethod on the builder (and, once resolution
 * descends through `@mixin Model`, on the model itself).
 *
 * Upstream layer: the non-merging behavior is a vimeo/psalm limitation, not a Laravel one — a
 * child's own `@mixin` should extend, not shadow, the inherited mixins. Until that is fixed,
 * we compensate at the plugin level by re-appending Query\Builder to the subclass's named
 * mixins so native mixin resolution (existence, params, return types, visibility) works again.
 *
 * Not to be confused with {@see ModelBuilderMixinHandler}, which works the inverse direction —
 * a Model's `@mixin Builder<static>` — and at analysis time rather than after population.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1140
 */
final class BuilderSubclassQueryMixinHandler implements AfterCodebasePopulatedInterface
{
    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();
        $builderLower = \strtolower(Builder::class);
        $queryBuilderLower = \strtolower(QueryBuilder::class);

        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            // Only classes that transitively extend Eloquent\Builder.
            if (!isset($storage->parent_classes[$builderLower])) {
                continue;
            }

            // Query\Builder already reachable as a named mixin → nothing was shadowed.
            // This is the no-own-`@mixin` case: Psalm inherited the parent's
            // `@mixin Query\Builder`, so namedMixins already carries it. Skip — and avoid
            // appending a duplicate.
            foreach ($storage->namedMixins as $mixin) {
                if (\strtolower($mixin->value) === $queryBuilderLower) {
                    continue 2;
                }
            }

            // The subclass shadowed the inherited mixin with its own. Re-add Query\Builder.
            // from_docblock mirrors a scanner-parsed `@mixin` tag — this restores the stub
            // `@mixin Query\Builder` the subclass would otherwise have inherited.
            $storage->namedMixins[] = new TNamedObject(QueryBuilder::class, from_docblock: true);

            // handleRegularMixins() only iterates namedMixins when mixin_declaring_fqcln is set.
            // The scanner already sets it on any class that declares its own `@mixin` (and the
            // populator copies a value from the parent otherwise), so reaching here with null is
            // not expected; keep the coalesce as a guard for that invariant.
            $storage->mixin_declaring_fqcln ??= $storage->name;
        }
    }
}
