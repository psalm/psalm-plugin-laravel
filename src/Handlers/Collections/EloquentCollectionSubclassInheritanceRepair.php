<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Collections;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\MethodIdentifier;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;

/**
 * Makes the methods the `Eloquent\Collection` stub re-declares reach userland subclasses.
 *
 * A stub override of a method that Laravel's own `Eloquent\Collection` inherits from `Support\Collection`
 * (`chunk`, `split`, `mapInto`, ...) applies to `Eloquent\Collection` receivers only: a subclass populated
 * earlier keeps `declaring_method_ids` pointing at `Support\Collection`, because
 * `Populator::inheritMethodsFromParent()` skips an id that is already set. Custom collections
 * (`#[CollectedBy]`, `newCollection()`) would then keep the `static<int, static>` return that fails the
 * `TModel of Model` bound (#1699).
 *
 * Only ids that still point at an ancestor of `Eloquent\Collection` are rewritten, and only for methods the
 * subclass neither declares nor imports from its own traits. Remove once Psalm applies a stub's
 * re-declaration of an inherited method to subclasses (same family as vimeo/psalm#12158).
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1699
 * @internal
 */
final class EloquentCollectionSubclassInheritanceRepair implements AfterCodebasePopulatedInterface
{
    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $provider = $event->getCodebase()->classlike_storage_provider;
        $ecLc = \strtolower(EloquentCollection::class);

        try {
            $ecStorage = $provider->get($ecLc);
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return;
        }

        foreach ($provider::getAll() as $storage) {
            if (!isset($storage->parent_classes[$ecLc])) {
                continue;
            }

            foreach ($ecStorage->methods as $methodLc => $_) {
                $declaring = $storage->declaring_method_ids[$methodLc] ?? null;

                // Own and own-trait methods already point at the subclass; ids that reach EC or a subclass of it
                // were resolved correctly. Anything left points above EC: the stale Support\Collection id.
                if ($declaring === null || isset($storage->methods[$methodLc])
                    || !isset($ecStorage->parent_classes[\strtolower($declaring->fq_class_name)])) {
                    continue;
                }

                $id = new MethodIdentifier($ecStorage->name, $methodLc);
                $storage->declaring_method_ids[$methodLc] = $id;
                $storage->appearing_method_ids[$methodLc] = $id;
                $storage->inheritable_method_ids[$methodLc] = $id;
            }
        }
    }
}
