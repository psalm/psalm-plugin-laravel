<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Magic;

use PhpParser\Node\Expr\MethodCall;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodParamsProviderInterface;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Union;

/**
 * Unified handler for all magic method forwarding patterns.
 *
 * Instead of writing a separate handler per forwarding pattern, this single handler
 * reads forwarding rules from a ForwardingChainRegistry and resolves return types
 * for any configured pattern.
 *
 * When a rule has interceptMixin=true, the handler also registers for the mixin
 * TARGET classes (searchClasses). When Psalm resolves a method via @mixin, the
 * return type provider fires for the target class (e.g., Builder). The handler
 * then checks if the ORIGINAL caller was a forwarding source (e.g., a Relation),
 * extracts its template params from the node type provider, and applies the
 * ForwardingStyle to return the correct type (e.g., HasMany<Comment, Post>
 * instead of Builder<TRelatedModel>).
 *
 * This approach is clean because:
 * - @mixin stays intact (Psalm handles method existence, params, visibility)
 * - We only override the return type (the one thing @mixin gets wrong)
 * - No need for existence/visibility/params providers
 * - Template params are available from the calling expression's type
 */
final class MethodForwardingHandler implements MethodParamsProviderInterface, MethodReturnTypeProviderInterface
{
    private static ?ForwardingChainRegistry $registry = null;

    /**
     * Initialize the handler with a registry of forwarding rules.
     * Must be called before Psalm analysis starts (during plugin initialization).
     *
     * @psalm-external-mutation-free
     */
    public static function init(ForwardingChainRegistry $registry): void
    {
        self::$registry = $registry;
    }

    /**
     * Returns all classes this handler should be registered for:
     * - Source classes (Relation, HasMany, etc.) — for methods declared in stubs
     * - Search classes (Builder, QueryBuilder) — for methods resolved via @mixin
     *
     * @return list<string>
     * @psalm-external-mutation-free
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        if (!self::$registry instanceof \Psalm\LaravelPlugin\Handlers\Magic\ForwardingChainRegistry) {
            return [];
        }

        $classes = self::$registry->getAllRegisteredClasses();

        // For interceptMixin rules, also register for search classes (mixin targets).
        // When @mixin resolves a method on Builder, the provider fires for Builder.
        // We need to intercept that to check if the caller was a Relation.
        foreach (self::$registry->getAllRules() as $rule) {
            if ($rule->interceptMixin) {
                foreach ($rule->searchClasses as $searchClass) {
                    $classes[] = $searchClass;
                }
            }
        }

        return \array_values(\array_unique($classes));
    }

    /**
     * Provide method parameter types for forwarded methods.
     *
     * When a method is called on a source class (e.g., HasOne::orderBy) but the
     * method actually exists on a search class (e.g., QueryBuilder::orderBy),
     * Psalm can't find the params in its storage. This provider returns the
     * target method's params so Psalm can check argument types.
     *
     * @return list<FunctionLikeParameter>|null
     */
    #[\Override]
    public static function getMethodParams(MethodParamsProviderEvent $event): ?array
    {
        if (!self::$registry instanceof \Psalm\LaravelPlugin\Handlers\Magic\ForwardingChainRegistry) {
            return null;
        }

        $rules = self::$registry->getRulesFor($event->getFqClasslikeName());
        if ($rules === []) {
            return null;
        }

        $source = $event->getStatementsSource();
        if (!$source instanceof \Psalm\StatementsSource) {
            return null;
        }

        $codebase = $source->getCodebase();
        $methodName = $event->getMethodNameLowercase();

        // Find the method on the first matching search class and return its params.
        foreach ($rules as $rule) {
            if (!$rule->interceptMixin) {
                continue;
            }

            foreach ($rule->searchClasses as $targetClass) {
                /** @var lowercase-string $methodName */
                $methodId = new MethodIdentifier($targetClass, $methodName);
                if ($codebase->methodExists($methodId)) {
                    return $codebase->methods->getMethodParams($methodId);
                }
            }
        }

        return null;
    }

    /** @psalm-external-mutation-free */
    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        if (!self::$registry instanceof \Psalm\LaravelPlugin\Handlers\Magic\ForwardingChainRegistry) {
            return null;
        }

        $source = $event->getSource();
        if (!$source instanceof StatementsAnalyzer) {
            return null;
        }

        $fqClassName = $event->getFqClasslikeName();
        $codebase = $source->getCodebase();
        $methodName = $event->getMethodNameLowercase();

        // Path 1: Direct rules — the handler is registered for the source class.
        // Handles two cases:
        // a) Methods declared in stubs on the source class (e.g., Relation::latest)
        //    — template params come from the event (normal method resolution)
        // b) Methods that fall to __call because they're only on QueryBuilder
        //    (e.g., orderBy, limit) — template params must be extracted from
        //    the calling expression's type via node_data/context
        $directRules = self::$registry->getRulesFor($fqClassName);
        if ($directRules !== []) {
            /** @psalm-suppress ImpureMethodCall node type provider access is read-only */
            $templateParams = $event->getTemplateTypeParameters()
                ?? self::extractTemplateParamsFromCaller($source, $event, $fqClassName);

            foreach ($directRules as $rule) {
                $result = ReturnTypeResolver::resolve(
                    rule: $rule,
                    sourceClass: $fqClassName,
                    sourceTemplateParams: $templateParams,
                    codebase: $codebase,
                    methodNameLowercase: $methodName,
                );

                if ($result instanceof \Psalm\Type\Union) {
                    return $result;
                }
            }
        }

        // Path 2: Mixin interception — the method was resolved via @mixin and
        // the provider fired for the mixin target class (e.g., Builder).
        // Check if the ORIGINAL caller was a forwarding source (e.g., a Relation).
        /** @psalm-suppress ImpureMethodCall node type provider access is read-only */
        return self::handleMixinInterception($source, $event, $fqClassName, $methodName);
    }

    /**
     * Extract template parameters from the calling expression's type.
     *
     * When the return type provider fires in the __call path (e.g., for
     * QueryBuilder-only methods like orderBy() on a Relation), Psalm doesn't
     * pass template parameters. We extract them from the calling expression's
     * type (e.g., HasOne<Phone, User>) via the node type provider or context.
     *
     * @return non-empty-list<Union>|null
     */
    private static function extractTemplateParamsFromCaller(
        StatementsAnalyzer $source,
        MethodReturnTypeProviderEvent $event,
        string $expectedClass,
    ): ?array {
        $stmt = $event->getStmt();
        if (!$stmt instanceof MethodCall) {
            return null;
        }

        $expectedClassLower = \strtolower($expectedClass);

        // Strategy 1: Get from node type provider (works for chained calls where
        // the previous call's return type has been stored on the MethodCall node).
        $varType = $source->getNodeTypeProvider()->getType($stmt->var);
        if ($varType instanceof \Psalm\Type\Union) {
            foreach ($varType->getAtomicTypes() as $atomicType) {
                if (
                    $atomicType instanceof TGenericObject
                    && \strtolower($atomicType->value) === $expectedClassLower
                ) {
                    return $atomicType->type_params;
                }
            }
        }

        // Strategy 2: Look up simple variables in context vars_in_scope.
        if ($stmt->var instanceof \PhpParser\Node\Expr\Variable && \is_string($stmt->var->name)) {
            $contextType = $event->getContext()->vars_in_scope['$' . $stmt->var->name] ?? null;
            if ($contextType !== null) {
                foreach ($contextType->getAtomicTypes() as $atomicType) {
                    if (
                        $atomicType instanceof TGenericObject
                        && \strtolower($atomicType->value) === $expectedClassLower
                    ) {
                        return $atomicType->type_params;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Handle method calls that arrived via @mixin resolution.
     *
     * When $relation->where() resolves via @mixin Builder, Psalm fires the provider
     * for Builder. We look at the actual calling expression's type to see if it's a
     * Relation (or other interceptMixin source), then apply the forwarding rule.
     */
    private static function handleMixinInterception(
        StatementsAnalyzer $source,
        MethodReturnTypeProviderEvent $event,
        string $mixinTargetClass,
        string $methodName,
    ): ?Union {
        $stmt = $event->getStmt();
        if (!$stmt instanceof MethodCall) {
            return null;
        }

        // Get the type of the expression the method was called on.
        // For $relation->where(), this is the type of $relation (e.g., HasOne<Phone, User>).
        $callerType = $source->getNodeTypeProvider()->getType($stmt->var);
        if (!$callerType instanceof \Psalm\Type\Union) {
            return null;
        }

        $codebase = $source->getCodebase();

        // Check each atomic type in the caller's union for a matching forwarding source.
        foreach ($callerType->getAtomicTypes() as $atomicType) {
            if (!$atomicType instanceof TGenericObject) {
                continue;
            }

            $callerClass = $atomicType->value;
            $callerClassLower = \strtolower($callerClass);

            // Find interceptMixin rules where:
            // 1. The caller is a registered source class
            // 2. The mixin target is one of the rule's search classes
            /** @var ForwardingChainRegistry $registry — null-checked in caller */
            $registry = self::$registry;
            foreach ($registry->getAllRules() as $rule) {
                if (!$rule->interceptMixin) {
                    continue;
                }

                // Check if the caller matches a source class for this rule
                $isSourceClass = false;
                foreach ($rule->allSourceClasses() as $sourceClass) {
                    if (\strtolower($sourceClass) === $callerClassLower) {
                        $isSourceClass = true;
                        break;
                    }
                }

                if (!$isSourceClass) {
                    // Also check if the caller is a subclass of a source class.
                    // This handles relation subclasses not explicitly listed.
                    foreach ($rule->allSourceClasses() as $sourceClass) {
                        if ($codebase->classExtendsOrImplements($callerClass, $sourceClass)) {
                            $isSourceClass = true;
                            break;
                        }
                    }
                }

                if (!$isSourceClass) {
                    continue;
                }

                // Check if the mixin target is one of this rule's search classes
                $isMixinTarget = false;
                foreach ($rule->searchClasses as $searchClass) {
                    if (\strtolower($searchClass) === \strtolower($mixinTargetClass)) {
                        $isMixinTarget = true;
                        break;
                    }
                }

                if (!$isMixinTarget) {
                    continue;
                }

                // Match! Apply the forwarding rule with the caller's template params.
                $templateParams = $atomicType->type_params;

                return ReturnTypeResolver::resolve(
                    rule: $rule,
                    sourceClass: $callerClass,
                    sourceTemplateParams: $templateParams,
                    codebase: $codebase,
                    methodNameLowercase: $methodName,
                );
            }
        }

        return null;
    }
}
