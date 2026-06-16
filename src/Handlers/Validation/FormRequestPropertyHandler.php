<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Validation;

use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\Plugin\EventHandler\Event\PropertyExistenceProviderEvent;
use Psalm\Plugin\EventHandler\Event\PropertyTypeProviderEvent;
use Psalm\Plugin\EventHandler\Event\PropertyVisibilityProviderEvent;
use Psalm\Type\Union;

/**
 * Narrows magic property reads on a FormRequest subclass — `$this->email`,
 * `$user->email` — to the type implied by the field's validation rule.
 *
 * Mirrors {@see ValidatedTypeHandler::resolveSelfInput} for the property
 * access shape: `Request::__get($key)` reads the input bag at runtime, so
 * the same presence-guarantee + rule-type narrowing applies. See issue #1016.
 *
 * Resolution priority (the first match wins, the rest are deferred to):
 * 1. A real declared property on the subclass — defer (return null).
 *    A user who writes `public string $email` opts out of the magic read.
 * 2. A `@property` / `@property-read` PHPDoc declaration — defer.
 * 3. A rule covered by `rules()` with an unconditional presence guarantee
 *    (required / present / accepted / declined, with `sometimes` absent).
 *
 * Non-presence-guaranteed fields and unknown fields return null, which
 * preserves the pre-plugin behaviour (Psalm continues normal analysis and
 * may emit `UndefinedThisPropertyFetch` for unknown keys). The trade-off
 * matches {@see ValidatedTypeHandler::resolveSelfInput} — narrowing only
 * fires when validation guarantees the field will exist post-validation.
 *
 * Route-param fallback: `Request::__get` reads `$this->all() + $route->parameters()`,
 * so at runtime a route-bound `{email}` segment also satisfies `$this->email`.
 * We deliberately ignore the route-param branch here for the same reason
 * `resolveSelfInput` does: the validation rule describes the input bag,
 * not the merged route+input map. Strict-by-default matches the `input()`
 * behavior the issue's caveat highlights.
 *
 * Registration: per-subclass via {@see FormRequestPropertyRegistrationHandler},
 * because Psalm's property provider lookup is exact-class (a closure for
 * `FormRequest::class` does not fire for `App\StoreUserRequest::$email`).
 *
 * @internal
 */
final class FormRequestPropertyHandler
{
    /**
     * Memoization for {@see resolveRuleForProperty}. Each three-provider
     * cycle (existence / visibility / type) on the same property fetch hits
     * this cache twice, and {@see ValidationTaintHandler} re-asks the same
     * question once per `addTaints` and once per `removeTaints` firing —
     * five calls per fetch in taint mode. The cache turns the per-fetch
     * cost into one `classlike_storage_provider->get()` + one rules lookup.
     *
     * Two-level: outer key is the lowercase FQCN, inner key is the
     * property name. Resolved value is the matching `ResolvedRule`, or
     * `null` for "no narrowing applies" (still cached to avoid re-walking).
     *
     * @var array<string, array<string, ?ResolvedRule>>
     */
    private static array $cache = [];

    public static function doesPropertyExist(PropertyExistenceProviderEvent $event): ?bool
    {
        if (!$event->isReadMode()) {
            return null;
        }

        if (!self::resolveRuleForProperty($event->getFqClasslikeName(), $event->getPropertyName()) instanceof ResolvedRule) {
            return null;
        }

        return true;
    }

    public static function isPropertyVisible(PropertyVisibilityProviderEvent $event): ?bool
    {
        if (!$event->isReadMode()) {
            return null;
        }

        if (!self::resolveRuleForProperty($event->getFqClasslikeName(), $event->getPropertyName()) instanceof ResolvedRule) {
            return null;
        }

        return true;
    }

    public static function getPropertyType(PropertyTypeProviderEvent $event): ?Union
    {
        if (!$event->isReadMode()) {
            return null;
        }

        $rule = self::resolveRuleForProperty($event->getFqClasslikeName(), $event->getPropertyName());

        return $rule?->type;
    }

    /**
     * Resolve the rule that narrows `$fqClasslikeName::$propertyName`, if any.
     *
     * Returns null when the user opted out via a real declaration / @property
     * PHPDoc, when no rule covers the field, or when the rule does not
     * guarantee presence. The same dispatch site that drives the type
     * narrowing — both `FormRequestPropertyHandler` (this class) and
     * {@see ValidatedFieldReadResolver::fromPropertyFetch} — share this
     * resolver so the type and taint paths agree on which fetches the
     * plugin owns. Drift between them produces either false-positive taint
     * on user-declared properties (taint fires while type defers) or missed
     * source on rule-narrowed reads.
     *
     * Public-but-internal: consumed only by `ValidationTaintHandler` to
     * mirror the gate. Not part of the public plugin API.
     */
    public static function resolveRuleForProperty(string $fqClasslikeName, string $propertyName): ?ResolvedRule
    {
        $cacheKey = \strtolower($fqClasslikeName);

        if (isset(self::$cache[$cacheKey]) && \array_key_exists($propertyName, self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey][$propertyName];
        }

        $resolved = self::doResolve($fqClasslikeName, $propertyName);

        self::$cache[$cacheKey][$propertyName] = $resolved;

        return $resolved;
    }

    /**
     * Uncached resolution. The outer `resolveRuleForProperty` memoizes the
     * result; this method runs at most once per (class, property) pair per
     * Psalm worker.
     */
    private static function doResolve(string $fqClasslikeName, string $propertyName): ?ResolvedRule
    {
        $declaredCheck = self::hasDeclaredProperty($fqClasslikeName, $propertyName);

        // Storage unavailable — we cannot prove the field is undeclared, so
        // refuse to narrow rather than risk shadowing a real declaration.
        // Same failure mode as a confirmed declared property.
        if ($declaredCheck === null || $declaredCheck) {
            return null;
        }

        /** @var class-string $fqClasslikeName — guaranteed by FormRequestPropertyRegistrationHandler's gating */
        $rules = ValidationRuleAnalyzer::getRulesForFormRequest($fqClasslikeName);

        if ($rules === null) {
            return null;
        }

        $rule = $rules[$propertyName] ?? null;

        if (!$rule instanceof ResolvedRule || !$rule->guaranteesPresence()) {
            return null;
        }

        return $rule;
    }

    /**
     * Reads from `classlike_storage` (already populated by Psalm's scanner)
     * rather than `property_exists()`. Avoids coupling to the autoloader —
     * the FormRequest's constructor or service dependencies may not be
     * resolvable in the analyzer process, but the scanned class shape is.
     *
     * Covers both real declarations (`public string $email`) and `@property`
     * / `@property-read` PHPDoc, including inherited declarations (Psalm
     * merges parent storage into `declaring_property_ids` and
     * `pseudo_property_get_types` during population).
     *
     * Returns:
     *   - `true`  — the user opted out; narrowing must defer.
     *   - `false` — no declaration exists; narrowing may proceed.
     *   - `null`  — storage is unavailable; caller must NOT narrow (we
     *               cannot prove the absence of a declaration). Distinct
     *               from `false` to avoid silently shadowing declared
     *               properties on classes whose storage failed to load.
     *
     * Two separate try/catch blocks so the failure mode of each call is
     * explicit. Mirrors the pattern in
     * {@see ValidatedTypeHandler::resolveSelfInput} (which splits the
     * ProjectAnalyzer access from the classExtends lookup).
     *
     * `@psalm-mutation-free` is honest here: every transitively-reached
     * Psalm API (`ProjectAnalyzer::getInstance`, `ClassLikeStorageProvider::get`)
     * carries the same annotation upstream, so the marker propagates cleanly
     * for taint-analysis call elision.
     *
     * @psalm-mutation-free
     */
    private static function hasDeclaredProperty(string $fqClasslikeName, string $propertyName): ?bool
    {
        try {
            $codebase = ProjectAnalyzer::getInstance()->getCodebase();
        } catch (\RuntimeException|\Error) {
            return null;
        }

        try {
            $classStorage = $codebase->classlike_storage_provider->get($fqClasslikeName);
        } catch (\InvalidArgumentException) {
            return null;
        }

        if (isset($classStorage->declaring_property_ids[$propertyName])) {
            return true;
        }

        return isset($classStorage->pseudo_property_get_types['$' . $propertyName]);
    }
}
