<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Validation;

use Illuminate\Foundation\Http\FormRequest;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\PropertyExistenceProviderEvent;
use Psalm\Plugin\EventHandler\Event\PropertyTypeProviderEvent;
use Psalm\Plugin\EventHandler\Event\PropertyVisibilityProviderEvent;
use Psalm\Type\Union;

/**
 * Narrows magic property reads on a FormRequest subclass (`$this->email`) to
 * the field's validation-rule type, the property-fetch analogue of
 * {@see ValidatedTypeHandler::resolveSelfInput} (`Request::__get` reads the
 * input bag). Defers (returns null) when the user opts out via a real
 * declaration or `@property` PHPDoc, when no rule covers the field, or when the
 * rule does not guarantee presence — leaving Psalm's normal analysis intact.
 * The route-param branch of `__get` is ignored, matching `input()` strictness.
 *
 * Discovery: Psalm's property provider lookup is exact-class, so
 * {@see afterCodebasePopulated} registers the three providers per FormRequest
 * subclass (mirroring {@see \Psalm\LaravelPlugin\Handlers\Eloquent\ModelRegistrationHandler}).
 * It also records the subclass set for the taint-path fast-bail
 * ({@see hasAnyFormRequests} / {@see isFormRequest}). No autoloader probe — the
 * providers read `classlike_storage` and the rule analyzer reads the AST.
 *
 * @internal
 */
final class FormRequestPropertyHandler implements AfterCodebasePopulatedInterface
{
    /**
     * Lowercase FQCNs of every concrete FormRequest subclass in the codebase.
     *
     * @var array<string, true>
     */
    private static array $formRequestClasses = [];

    /**
     * Memoizes {@see resolveRuleForProperty}: the three providers plus the
     * taint handler's add/remove ask the same (class, property) question up to
     * five times per fetch. Outer key lowercase FQCN, inner key property name;
     * null ("no narrowing") is cached too.
     *
     * @var array<string, array<string, ?ResolvedRule>>
     */
    private static array $cache = [];

    /**
     * @inheritDoc
     *
     * `@psalm-external-mutation-free` is a slight overclaim (the registered
     * closures mutate Psalm's provider tables) but Psalm 7's `MissingPureAnnotation`
     * demands it for taint analysis, and project policy forbids baseline entries.
     * Same disclaimer as {@see InlineValidateRulesCollector::afterStatementAnalysis}.
     *
     * @psalm-external-mutation-free
     */
    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        // Psalm can run the plugin more than once in a single long-lived
        // process when a tooling or test harness analyzes several codebases in
        // sequence. Rebuild the registry from the current populated codebase
        // instead of carrying stale rule answers across runs.
        self::$formRequestClasses = [];
        self::$cache = [];

        $codebase = $event->getCodebase();
        $formRequestFqcn = \strtolower(FormRequest::class);

        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            if ($storage->abstract) {
                continue;
            }

            // parent_classes is keyed by lowercase FQCN over the full chain, so
            // multi-level subclasses (FormRequest <- Base <- Store) still match.
            if (!isset($storage->parent_classes[$formRequestFqcn])) {
                continue;
            }

            $className = $storage->name;
            $properties = $codebase->properties;

            self::$formRequestClasses[\strtolower($className)] = true;

            $properties->property_existence_provider->registerClosure($className, self::doesPropertyExist(...));
            $properties->property_visibility_provider->registerClosure($className, self::isPropertyVisible(...));
            $properties->property_type_provider->registerClosure($className, self::getPropertyType(...));
        }
    }

    /**
     * `false` short-circuits the per-expression PropertyFetch work in
     * {@see ValidatedFieldReadResolver::fromPropertyFetch} on projects with no
     * FormRequest subclasses.
     *
     * @psalm-external-mutation-free
     */
    public static function hasAnyFormRequests(): bool
    {
        return self::$formRequestClasses !== [];
    }

    /**
     * Whether `$fqClasslikeName` is a known FormRequest subclass — lets the
     * taint resolver skip the `classExtends` walk for non-FormRequest callers.
     *
     * @psalm-external-mutation-free
     */
    public static function isFormRequest(string $fqClasslikeName): bool
    {
        return isset(self::$formRequestClasses[\strtolower($fqClasslikeName)]);
    }

    public static function doesPropertyExist(PropertyExistenceProviderEvent $event): ?bool
    {
        if (!$event->isReadMode()) {
            return null;
        }

        return self::resolveRuleForProperty($event->getFqClasslikeName(), $event->getPropertyName()) instanceof ResolvedRule
            ? true
            : null;
    }

    public static function isPropertyVisible(PropertyVisibilityProviderEvent $event): ?bool
    {
        if (!$event->isReadMode()) {
            return null;
        }

        return self::resolveRuleForProperty($event->getFqClasslikeName(), $event->getPropertyName()) instanceof ResolvedRule
            ? true
            : null;
    }

    public static function getPropertyType(PropertyTypeProviderEvent $event): ?Union
    {
        if (!$event->isReadMode()) {
            return null;
        }

        return self::resolveRuleForProperty($event->getFqClasslikeName(), $event->getPropertyName())?->type;
    }

    /**
     * Rule narrowing `$fqClasslikeName::$propertyName`, or null when the user
     * opted out / no rule covers it / the rule does not guarantee presence.
     * Shared with {@see ValidatedFieldReadResolver::fromPropertyFetch} so the
     * type and taint paths own exactly the same set of fetches — drift would
     * mean false-positive taint on declared properties or a missed source.
     */
    public static function resolveRuleForProperty(string $fqClasslikeName, string $propertyName): ?ResolvedRule
    {
        $cacheKey = \strtolower($fqClasslikeName);

        if (isset(self::$cache[$cacheKey]) && \array_key_exists($propertyName, self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey][$propertyName];
        }

        return self::$cache[$cacheKey][$propertyName] = self::doResolve($fqClasslikeName, $propertyName);
    }

    private static function doResolve(string $fqClasslikeName, string $propertyName): ?ResolvedRule
    {
        // null (storage unavailable) or true (declared) → refuse to narrow,
        // rather than risk shadowing a real declaration.
        if (self::hasDeclaredProperty($fqClasslikeName, $propertyName) !== false) {
            return null;
        }

        /** @var class-string $fqClasslikeName — guaranteed by the discovery gating */
        $rules = ValidationRuleAnalyzer::getRulesForFormRequest($fqClasslikeName);
        $rule = $rules[$propertyName] ?? null;

        return $rule instanceof ResolvedRule && $rule->guaranteesPresence() ? $rule : null;
    }

    /**
     * Whether a real or `@property` declaration exists for the field, read from
     * `classlike_storage` (avoids autoloader coupling; covers inherited
     * declarations Psalm merges during population). Returns null when storage
     * is unavailable — distinct from `false` so the caller does not narrow on a
     * class whose shape failed to load.
     *
     * `@psalm-mutation-free` holds: every Psalm API reached carries it upstream.
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

        return isset($classStorage->declaring_property_ids[$propertyName])
            || isset($classStorage->pseudo_property_get_types['$' . $propertyName]);
    }
}
