<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Rules;

use Illuminate\Database\Eloquent\Model;
use Psalm\CodeLocation;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Issues\PublicModelAccessor;
use Psalm\LaravelPlugin\Issues\PublicModelScope;
use Psalm\LaravelPlugin\Util\EloquentModelMethods;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Storage\MethodStorage;

/**
 * Flags `public` Eloquent query scopes and legacy attribute accessors/mutators, which Laravel's
 * convention wants `protected` (they are dispatched indirectly and never called by name). Emits
 * {@see PublicModelScope} for scopes (legacy `scopeXxx()` and `#[Scope]`) and {@see PublicModelAccessor}
 * for legacy `getXxxAttribute()` / `setXxxAttribute()`. See those issue classes for the full rationale,
 * the public-only decision, and how the coverage differs from Larastan's NoPublicModelScopeAndAccessorRule.
 *
 * Enabled by default: registered unconditionally (see Plugin::registerHandlers()), like ModelMakeHandler.
 * Silence per project through the issueHandlers config.
 *
 * Hook choice — AfterCodebasePopulated:
 *  - Inheritance is fully resolved, so a scope/accessor hosted on a trait resolves to its declaring
 *    storage via {@see EloquentModelMethods::appearingMethods()} (the declaring-storage path #1048
 *    introduced for the suppressors), and is reported at the trait's own declaration.
 *  - A single pass over the codebase lets us dedupe a trait scope shared by several models to one
 *    report (a trait method yields the same MethodStorage instance once per composing model).
 *  - The hook fires on every full-scan run, which is the only mode in the pinned Psalm 7 beta (it
 *    hardcodes `is_diff = false`). Emissions survive worker forking: the parent emits before
 *    `analyzeFiles()` forks, and `IssueBuffer::addIssues()` merges worker issues on top without
 *    resetting the parent buffer (verified under `--threads`). The trade-off versus an analysis-time
 *    hook: were Psalm to re-enable `--diff`, a warm run that skips the declaring file would not re-emit,
 *    because populate-time emissions are not replayed from the per-file issue cache.
 */
final class PublicScopeAccessorVisibilityHandler implements AfterCodebasePopulatedInterface
{
    /** @inheritDoc */
    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $provider = $event->getCodebase()->classlike_storage_provider;

        // A trait scope/accessor is yielded once per composing model (the same shared MethodStorage),
        // so dedupe on storage identity to emit one report at the trait declaration. `add()` would also
        // dedupe by location+message, but skipping here avoids the redundant accepts() calls.
        $reported = [];

        foreach ($provider::getAll() as $classStorage) {
            if (!$classStorage->user_defined || $classStorage->is_interface) {
                continue;
            }

            // Mirror SuppressHandler: parent_classes stores FQCNs in original case, so Model::class
            // (no leading backslash) matches. Traits never extend Model, so a scope-hosting trait is
            // reached only through a composing model below, never iterated as a top-level target.
            if (!\in_array(Model::class, $classStorage->parent_classes, true)) {
                continue;
            }

            foreach (EloquentModelMethods::appearingMethods($classStorage, $provider) as $methodName => $methodStorage) {
                // The convention violation is the PUBLIC case only: protected is correct, and private is
                // a separate dead-code concern (see the issue classes). Filter before classifying.
                if ($methodStorage->visibility !== ClassLikeAnalyzer::VISIBILITY_PUBLIC) {
                    continue;
                }

                $kind = self::classify($methodName, $methodStorage);
                if ($kind === null) {
                    continue;
                }

                $location = $methodStorage->location;
                if (!$location instanceof CodeLocation) {
                    continue;
                }

                $storageId = \spl_object_id($methodStorage);
                if (isset($reported[$storageId])) {
                    continue;
                }

                $reported[$storageId] = true;

                $casedName = $methodStorage->cased_name ?? $methodName;

                // Messages are anchored on the method (not the model) so a trait scope shared by several
                // models dedupes to one diagnostic at the trait declaration: same type, location, message.
                $issue = $kind === 'scope'
                    ? new PublicModelScope(self::scopeMessage($casedName), $location)
                    : new PublicModelAccessor(self::accessorMessage($casedName), $location);

                IssueBuffer::accepts($issue, $methodStorage->suppressed_issues);
            }
        }
    }

    /**
     * Classify a public model method as a query 'scope', an attribute 'accessor', or null (neither).
     *
     * @return 'scope'|'accessor'|null
     *
     * @psalm-mutation-free
     */
    private static function classify(string $methodName, MethodStorage $methodStorage): ?string
    {
        if (EloquentModelMethods::isLegacyScopeMethodName($methodName, $methodStorage->cased_name)
            || EloquentModelMethods::hasScopeAttribute($methodStorage)
        ) {
            return 'scope';
        }

        if (EloquentModelMethods::isLegacyAccessorMethodName($methodName)) {
            return 'accessor';
        }

        return null;
    }

    /** @psalm-pure */
    private static function scopeMessage(string $methodName): string
    {
        return "Eloquent query scope {$methodName}() should be protected, not public. "
            . 'Scopes are dispatched through the query builder and never called by name, so public '
            . 'only widens the model API (and a public #[Scope] called statically is a runtime fatal).';
    }

    /** @psalm-pure */
    private static function accessorMessage(string $methodName): string
    {
        return "Eloquent attribute accessor/mutator {$methodName}() should be protected, not public. "
            . 'Accessors and mutators are dispatched via __get() / __set() magic and never called by '
            . 'name, so public only widens the model API.';
    }
}
