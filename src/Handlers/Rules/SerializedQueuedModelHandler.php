<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Rules;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Issues\SerializedQueuedModel;
use Psalm\Plugin\EventHandler\AfterClassLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeAnalysisEvent;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Flags a queued class that holds an Eloquent model in a property while nothing on the class
 * prepares that model for serialization.
 *
 * `SerializesModels::__serialize()` replaces each model with a `ModelIdentifier` and
 * re-resolves it in `__unserialize()`. Without it the whole model — every attribute and
 * every loaded relation — is written into the queue payload, so the worker runs against a
 * snapshot taken at dispatch time, payloads grow with the model, and a payload written
 * before a schema change can fail to unserialise after it.
 *
 * `__serialize()`, not the trait name
 * ----------------------------------
 * The trait is almost never used directly (`Illuminate\Foundation\Queue\Queueable`, what
 * `make:job` scaffolds since Laravel 11, brings it in; so does `Notifications\Notification`;
 * so do project base classes and shared traits), so a direct-`use` check reports mostly false
 * positives. On a 19-application corpus that is the difference between 37 reports and 1.
 *
 * Rather than walk the trait and parent closure looking for one trait by name, the check asks
 * whether the class ends up with a `__serialize()` or `__sleep()` at all: Psalm's populator
 * has already flattened both across traits, traits of traits, and ancestors. That also clears
 * a class that hand-writes its own serialization instead of using the trait, which is correct
 * as written and must not be reported.
 *
 * Only concrete classes are inspected: an abstract queued base may legitimately leave
 * serialization to its children.
 *
 * The report is per class, not per property: the fix is one `use SerializesModels;`, and no
 * per-property decision exists, so the offending properties are named in the message instead.
 *
 * Known gaps, all in the safe direction: a model held in a plain `array`, a property declared
 * by an abstract ancestor (nobody is answerable for it), and a `Support\Collection` of models,
 * which the trait would not fix either since it is not a `QueueableCollection`.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1380
 * @internal
 */
final class SerializedQueuedModelHandler implements AfterClassLikeAnalysisInterface
{
    private const SHOULD_QUEUE = 'illuminate\contracts\queue\shouldqueue';

    private const MAX_LISTED_PROPERTIES = 3;

    /** @inheritDoc */
    #[\Override]
    public static function afterStatementAnalysis(AfterClassLikeAnalysisEvent $event): ?bool
    {
        $storage = $event->getClasslikeStorage();

        // Interfaces and traits carry no payload; an abstract queued base may leave
        // serialization to its children, so only concrete classes are answerable for it.
        if ($storage->is_interface || $storage->is_trait || $storage->abstract) {
            return null;
        }

        if (!isset($storage->class_implements[self::SHOULD_QUEUE])) {
            return null;
        }

        // Any inherited `__serialize()`/`__sleep()` means the class decides what goes into the
        // payload: `SerializesModels`, a parent, or a hand-written one.
        if (isset($storage->appearing_method_ids['__serialize'])
            || isset($storage->appearing_method_ids['__sleep'])
        ) {
            return null;
        }

        $location = $storage->location ?? $storage->stmt_location;

        if (!$location instanceof CodeLocation) {
            return null;
        }

        $codebase = $event->getCodebase();
        $offenders = [];

        foreach ($storage->properties as $propertyName => $property) {
            // Static properties are skipped by SerializesModels::__serialize() itself.
            if ($property->is_static === true) {
                continue;
            }

            if (!self::carriesModel($property->type, $codebase)) {
                continue;
            }

            $offenders[] = "\${$propertyName}";
        }

        if ($offenders === []) {
            return null;
        }

        // One report per class, anchored at the class name: the fix is a single
        // `use SerializesModels;`, so a per-property report would be N findings for one edit.
        IssueBuffer::accepts(
            new SerializedQueuedModel(
                "{$storage->name} implements ShouldQueue without "
                . 'Illuminate\Queue\SerializesModels, so '
                . self::listOffenders($offenders)
                . ' will be serialized whole into the queue payload',
                $location,
            ),
            // The class docblock is where a deliberate exception is written, and its
            // suppressions do not reach the statements source at this point.
            $storage->suppressed_issues + $event->getStatementsSource()->getSuppressedIssues(),
        );

        return null;
    }

    /**
     * @param non-empty-list<string> $offenders
     *
     * @psalm-pure
     */
    private static function listOffenders(array $offenders): string
    {
        // A job holding many models would otherwise turn the message into a paragraph.
        $shown = \array_slice($offenders, 0, self::MAX_LISTED_PROPERTIES);
        $hidden = \count($offenders) - \count($shown);

        return \implode(', ', $shown) . ($hidden > 0 ? " and {$hidden} more" : '');
    }

    /**
     * Whether the declared type carries a model or an Eloquent collection, the two shapes
     * `getSerializedPropertyValue()` converts.
     *
     * @psalm-external-mutation-free
     */
    private static function carriesModel(?Union $type, Codebase $codebase): bool
    {
        foreach ($type?->getAtomicTypes() ?? [] as $atomic) {
            if ($atomic instanceof TNamedObject
                && (self::isOrExtends($atomic->value, Model::class, $codebase)
                    || self::isOrExtends($atomic->value, EloquentCollection::class, $codebase))
            ) {
                return true;
            }
        }

        return false;
    }

    // Not marked mutation-free: Psalm 6's Codebase::classOrInterfaceExists() and classExtends()
    // are not annotated mutation-free, unlike Psalm 7.
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
}
