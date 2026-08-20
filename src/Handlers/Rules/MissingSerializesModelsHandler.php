<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Rules;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Issues\MissingSerializesModels;
use Psalm\Plugin\EventHandler\AfterClassLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeAnalysisEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Flags a queued class that holds an Eloquent model in a property without being able to
 * reach `Illuminate\Queue\SerializesModels`.
 *
 * `SerializesModels::__serialize()` replaces each model with a `ModelIdentifier` and
 * re-resolves it in `__unserialize()`. Without it the whole model — every attribute and
 * every loaded relation — is written into the queue payload, so the worker runs against a
 * snapshot taken at dispatch time, payloads grow with the model, and a payload written
 * before a schema change can fail to unserialise after it.
 *
 * Reachability, not a direct `use`
 * --------------------------------
 * The trait is almost always inherited rather than used directly, so a direct-`use` check
 * reports mostly false positives. Three framework bases already pull it in:
 *
 *   - `Illuminate\Foundation\Queue\Queueable` (what `make:job` scaffolds since Laravel 11)
 *     is `use Dispatchable, InteractsWithQueue, QueueableByBus, SerializesModels;`
 *   - `Illuminate\Notifications\Notification` uses `SerializesModels` directly
 *   - `Illuminate\Mail\Mailable` reaches it through `Illuminate\Bus\Queueable`'s neighbours
 *     in the same way
 *
 * So the check walks the complete trait closure (traits of traits) of the class AND of every
 * ancestor. On a 19-application corpus this is the difference between 37 reports and 1.
 *
 * Only concrete classes are inspected: an abstract queued base may legitimately leave the
 * trait to its children.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1380
 * @internal
 */
final class MissingSerializesModelsHandler implements AfterClassLikeAnalysisInterface
{
    private const SERIALIZES_MODELS = 'illuminate\queue\serializesmodels';

    private const SHOULD_QUEUE = 'illuminate\contracts\queue\shouldqueue';

    /** @inheritDoc */
    #[\Override]
    public static function afterStatementAnalysis(AfterClassLikeAnalysisEvent $event): ?bool
    {
        $storage = $event->getClasslikeStorage();

        // Interfaces and traits carry no payload; an abstract queued base may leave the
        // trait to its children, so only concrete classes are answerable for it.
        if ($storage->is_interface || $storage->is_trait || $storage->abstract) {
            return null;
        }

        if (!isset($storage->class_implements[self::SHOULD_QUEUE])) {
            return null;
        }

        $codebase = $event->getCodebase();

        if (self::reachesSerializesModels($storage, $codebase)) {
            return null;
        }

        $source = $event->getStatementsSource();

        foreach ($storage->properties as $propertyName => $property) {
            // Static properties are skipped by SerializesModels::__serialize() itself.
            if ($property->is_static === true) {
                continue;
            }

            // Report where the property is written, not where it is inherited from: a
            // parent's property is the parent's to fix.
            if (($storage->declaring_property_ids[$propertyName] ?? null) !== $storage->name) {
                continue;
            }

            $location = $property->stmt_location ?? $property->location;

            if ($location === null) {
                continue;
            }

            $modelClass = self::findModelClass($property->type, $codebase);

            if ($modelClass === null) {
                continue;
            }

            IssueBuffer::accepts(
                new MissingSerializesModels(
                    "{$storage->name} implements ShouldQueue and holds {$modelClass} in \${$propertyName}, "
                    . 'but does not use Illuminate\Queue\SerializesModels, so the entire model is serialized '
                    . 'into the queue payload. Add `use SerializesModels;` to serialize an identifier and '
                    . 'reload the model in the worker.',
                    $location,
                ),
                $source->getSuppressedIssues(),
            );
        }

        return null;
    }

    /**
     * Whether `SerializesModels` is reachable through the class's own trait closure or that
     * of any ancestor.
     *
     * @psalm-mutation-free
     */
    private static function reachesSerializesModels(ClassLikeStorage $storage, Codebase $codebase): bool
    {
        $storages = [$storage];

        foreach (\array_keys($storage->parent_classes) as $parent) {
            $parentStorage = self::storageFor($parent, $codebase);

            if ($parentStorage instanceof ClassLikeStorage) {
                $storages[] = $parentStorage;
            }
        }

        foreach ($storages as $candidate) {
            if (self::traitClosureContainsSerializesModels($candidate, $codebase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Breadth-first walk of `used_traits`, including traits used by those traits.
     *
     * @psalm-mutation-free
     */
    private static function traitClosureContainsSerializesModels(ClassLikeStorage $storage, Codebase $codebase): bool
    {
        $pending = \array_keys($storage->used_traits);
        $seen = [];

        while ($pending !== []) {
            $trait = \array_pop($pending);

            if ($trait === self::SERIALIZES_MODELS) {
                return true;
            }

            if (isset($seen[$trait])) {
                continue;
            }

            $seen[$trait] = true;

            $traitStorage = self::storageFor($trait, $codebase);

            if ($traitStorage instanceof ClassLikeStorage) {
                foreach (\array_keys($traitStorage->used_traits) as $nested) {
                    $pending[] = $nested;
                }
            }
        }

        return false;
    }

    /**
     * The declared type of a property, reduced to the first Eloquent model it carries:
     * a model, an Eloquent collection, or a `Support\Collection` parameterised by a model.
     *
     * @psalm-external-mutation-free
     */
    private static function findModelClass(?Union $type, Codebase $codebase): ?string
    {
        foreach ($type?->getAtomicTypes() ?? [] as $atomic) {
            if (!$atomic instanceof TNamedObject) {
                continue;
            }

            if (self::isModel($atomic->value, $codebase)) {
                return $atomic->value;
            }

            if (self::isOrExtends($atomic->value, EloquentCollection::class, $codebase)) {
                return $atomic->value;
            }

            // A Support\Collection is only interesting when it is parameterised by a model;
            // an unparameterised one says nothing about what it will hold.
            if ($atomic instanceof TGenericObject && self::isOrExtends($atomic->value, Collection::class, $codebase)) {
                foreach ($atomic->type_params as $param) {
                    foreach ($param->getAtomicTypes() as $inner) {
                        if ($inner instanceof TNamedObject && self::isModel($inner->value, $codebase)) {
                            return $atomic->value . '<' . $inner->value . '>';
                        }
                    }
                }
            }
        }

        return null;
    }

    /** @psalm-external-mutation-free */
    private static function isModel(string $className, Codebase $codebase): bool
    {
        return self::isOrExtends($className, Model::class, $codebase);
    }

    /** @psalm-external-mutation-free */
    private static function isOrExtends(string $className, string $parent, Codebase $codebase): bool
    {
        if (\strtolower($className) === \strtolower($parent)) {
            return true;
        }

        if (!$codebase->classOrInterfaceExists($className)) {
            return false;
        }

        return $codebase->classExtends($className, $parent);
    }

    /**
     * Storage lookups can fire before the class is populated; that is "not proven", not an error.
     *
     * @psalm-mutation-free
     */
    private static function storageFor(string $classLikeName, Codebase $codebase): ?ClassLikeStorage
    {
        try {
            return $codebase->classlike_storage_provider->get(\strtolower($classLikeName));
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return null;
        }
    }
}
