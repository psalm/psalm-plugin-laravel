<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Type\Atomic\TNamedObject;

/**
 * Keeps `@mixin Query\Builder` as the highest-priority mixin of user Eloquent\Builder subclasses
 * which declare their own `@mixin` (typically `@mixin <Model>`).
 *
 * Eloquent\Builder forwards Query\Builder methods (whereNotNull, orWhereNull, orderBy, ...)
 * through a stub `@mixin \Illuminate\Database\Query\Builder`. When a subclass adds its own
 * `@mixin Model` (a common pattern to expose model attributes/scopes on builder instances), that
 * model also claims the Query\Builder methods: the plugin's per-model existence provider confirms
 * them for the model's own `__call` forwarding. Psalm's mixin lookup is last-match-wins, so if
 * the model mixin sits above Query\Builder, `$builder->whereNotNull()` resolves through the model
 * and fails (UndefinedMethod on the model). Before Psalm 7.5 the populator also dropped the
 * inherited mixin entirely (vimeo/psalm#13671). Since 7.5 it merges the inherited mixin below
 * the child's own ones, which is the order that loses here.
 *
 * So Query\Builder is (re)placed last in the subclass's named mixins, letting native mixin
 * resolution (existence, params, return types, visibility) answer the forwarded methods while
 * model members not on Query\Builder still resolve through the model mixin.
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

            $mixins = $storage->namedMixins;
            $last = \end($mixins);

            // No own `@mixin` (the inherited list is just Query\Builder), or Query\Builder already wins.
            if ($last instanceof TNamedObject && \strtolower($last->value) === $queryBuilderLower) {
                continue;
            }

            $storage->namedMixins = [
                ...\array_values(\array_filter(
                    $mixins,
                    static fn(TNamedObject $mixin): bool => \strtolower($mixin->value) !== $queryBuilderLower,
                )),
                // from_docblock mirrors a scanner-parsed `@mixin` tag, like the stub's own.
                new TNamedObject(QueryBuilder::class, from_docblock: true),
            ];

            // handleRegularMixins() only iterates namedMixins when mixin_declaring_fqcln is set.
            // The scanner already sets it on any class that declares its own `@mixin` (and the
            // populator copies a value from the parent otherwise), so reaching here with null is
            // not expected; keep the coalesce as a guard for that invariant.
            $storage->mixin_declaring_fqcln ??= $storage->name;
        }
    }
}
