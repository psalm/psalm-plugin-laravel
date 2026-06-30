<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Rules;

use Illuminate\Database\Eloquent\Model;
use Psalm\CodeLocation;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\EloquentModelMethods;
use Psalm\LaravelPlugin\Issues\PublicModelAccessor;
use Psalm\LaravelPlugin\Issues\PublicModelScope;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Storage\MethodStorage;

/**
 * Flags `public` Eloquent `#[Scope]` methods and legacy attribute accessors/mutators, which Laravel's
 * convention wants `protected` (they are dispatched indirectly and never called by name). Routes by the
 * method's danger: a `public` `#[Scope]` (the only one with a static-call runtime footgun) emits
 * {@see PublicModelScope}; a legacy `getXxxAttribute()` / `setXxxAttribute()` accessor (pure convention)
 * emits {@see PublicModelAccessor}. Those issue classes carry the full rationale, their error levels, and
 * the public-only decision.
 *
 * Legacy `scopeXxx()` methods are deliberately NOT flagged: Laravel's own documentation writes local
 * scopes as `public function scopeActive()`, so `public` is the framework's documented idiom there, not a
 * smell. Visibility is irrelevant to their `$builder->active()` dispatch, and reporting them lit up the
 * normal style across whole codebases (see the v4.13.2 benchmark). Only the `#[Scope]` form, whose static
 * call is a genuine runtime fatal, is worth flagging.
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

                // Skip a method whose `public` visibility is dictated by a contract it satisfies: an
                // interface method, an abstract parent/trait method it implements, or a parent method it
                // overrides cannot be narrowed to `protected` (PHP forbids reducing visibility below the
                // inherited or required declaration), so the report would be unactionable. Psalm's
                // Populator fills overridden_method_ids from implemented interfaces, parent classes, and
                // abstract trait requirements. Test for a NON-EMPTY list: the key is also present with an
                // empty array for a root declaration that overrides nothing, so isset() would over-skip.
                if (($classStorage->overridden_method_ids[$methodName] ?? []) !== []) {
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

                // Route to the issue whose ERROR_LEVEL matches the method's danger. Messages are anchored on
                // the method (not the model), so a trait method shared by several models dedupes to one
                // diagnostic at the trait declaration: same type, location, message.
                //  - #[Scope] is the only static-call footgun -> PublicModelScope (error at levels 1-4).
                //  - legacy accessors are pure convention -> PublicModelAccessor (level 1).
                $issue = match ($kind) {
                    'scope-attribute' => new PublicModelScope(self::scopeAttributeMessage($casedName), $location),
                    'accessor' => new PublicModelAccessor(self::accessorMessage($casedName), $location),
                };

                IssueBuffer::accepts($issue, $methodStorage->suppressed_issues);
            }
        }
    }

    /**
     * Classify a public model method by the convention it breaks, or null if it is neither a `#[Scope]`
     * method nor a legacy accessor. Legacy `scopeXxx()` methods are intentionally ignored (see the class
     * docblock): `public` is Laravel's documented idiom for them, so they are not a convention violation.
     *
     * @return 'scope-attribute'|'accessor'|null
     *
     * @psalm-mutation-free
     */
    private static function classify(string $methodName, MethodStorage $methodStorage): ?string
    {
        if (EloquentModelMethods::hasScopeAttribute($methodStorage)) {
            return 'scope-attribute';
        }

        if (EloquentModelMethods::isLegacyAccessorMethodName($methodName)) {
            return 'accessor';
        }

        return null;
    }

    /** @psalm-pure */
    private static function scopeAttributeMessage(string $methodName): string
    {
        return "Eloquent #[Scope] method {$methodName}() should be protected, not public: called statically "
            . "(Model::{$methodName}()) it is a runtime fatal.";
    }

    /** @psalm-pure */
    private static function accessorMessage(string $methodName): string
    {
        return "Eloquent accessor/mutator {$methodName}() should be protected, not public; it is dispatched "
            . 'via __get()/__set(), never by name.';
    }
}
