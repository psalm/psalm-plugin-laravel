<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Validation;

use Illuminate\Foundation\Http\FormRequest;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;

/**
 * Discovers FormRequest subclasses from Psalm's scanned codebase and
 * registers {@see FormRequestPropertyHandler}'s three providers per-class.
 *
 * Psalm's property provider lookup is exact-class (see
 * \Psalm\Internal\Codebase\Properties::propertyExists): a closure registered
 * for `FormRequest::class` is never consulted for `App\StoreUserRequest::$email`.
 * Mirrors the per-class registration pattern in
 * {@see \Psalm\LaravelPlugin\Handlers\Eloquent\ModelRegistrationHandler}.
 *
 * No autoloader probe (unlike the Eloquent handler): the property handler
 * reads `classlike_storage` and the rule analyzer reads the AST, so we
 * never need a runtime class instance.
 *
 * Side outputs (lowercase-FQCN set in {@see $formRequestClasses}) feed the
 * fast-bail in {@see ValidatedFieldReadResolver::fromPropertyFetch}.
 * The taint handler fires on every expression under taint analysis, so a
 * cheap `isset` lookup against this set lets the common "caller is not a
 * FormRequest" case skip the `classExtends` storage walk entirely.
 *
 * @internal
 */
final class FormRequestPropertyRegistrationHandler implements AfterCodebasePopulatedInterface
{
    /**
     * Lowercase FQCNs of every concrete FormRequest subclass discovered in
     * the codebase. Populated by {@see afterCodebasePopulated}.
     *
     * @var array<string, true>
     */
    private static array $formRequestClasses = [];

    /**
     * @inheritDoc
     *
     * `@psalm-external-mutation-free` is a slight overclaim here (the closures
     * we register mutate Psalm's per-class provider tables), mirroring the
     * disclaimer in {@see \Psalm\LaravelPlugin\Handlers\Validation\InlineValidateRulesCollector::afterStatementAnalysis}.
     * Psalm 7's `MissingPureAnnotation` check demands it for taint analysis,
     * and project policy forbids new `psalm-baseline.xml` entries.
     *
     * @psalm-external-mutation-free
     */
    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();
        $formRequestFqcn = \strtolower(FormRequest::class);

        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            if ($storage->abstract) {
                continue;
            }

            // parent_classes is keyed by lowercase FQCN and includes the full
            // inheritance chain, so a multi-level subclass (FormRequest <-
            // BaseRequest <- StoreUserRequest) still matches here.
            if (!isset($storage->parent_classes[$formRequestFqcn])) {
                continue;
            }

            $className = $storage->name;
            $properties = $codebase->properties;

            self::$formRequestClasses[\strtolower($className)] = true;

            $properties->property_existence_provider->registerClosure(
                $className,
                FormRequestPropertyHandler::doesPropertyExist(...),
            );
            $properties->property_visibility_provider->registerClosure(
                $className,
                FormRequestPropertyHandler::isPropertyVisible(...),
            );
            $properties->property_type_provider->registerClosure(
                $className,
                FormRequestPropertyHandler::getPropertyType(...),
            );
        }
    }

    /**
     * Fast bail-out probe for taint-mode hot paths — `false` short-circuits
     * the per-expression PropertyFetch resolution work in
     * {@see ValidatedFieldReadResolver::fromPropertyFetch} for projects
     * with zero FormRequest subclasses. Same pattern as
     * {@see InlineValidateRulesCollector::hasAnyVariableBindings}.
     *
     * `@psalm-external-mutation-free` rather than `@psalm-mutation-free`
     * because the method reads a static property; same disclaimer as the
     * sibling {@see InlineValidateRulesCollector::hasAnyVariableBindings}.
     *
     * @psalm-external-mutation-free
     */
    public static function hasAnyFormRequests(): bool
    {
        return self::$formRequestClasses !== [];
    }

    /**
     * Whether the given FQCN was registered as a FormRequest subclass.
     * Lets {@see ValidatedFieldReadResolver::fromPropertyFetch} skip the
     * `classExtends` storage walk for callers whose class is not in the set
     * (which is the overwhelming majority of property reads under taint
     * analysis on any non-trivial project).
     *
     * @psalm-external-mutation-free
     */
    public static function isFormRequest(string $fqClasslikeName): bool
    {
        return isset(self::$formRequestClasses[\strtolower($fqClasslikeName)]);
    }
}
