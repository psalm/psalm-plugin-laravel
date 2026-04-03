<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Magic;

/**
 * Central registry of all forwarding rules.
 *
 * Holds the complete set of ForwardingRules and provides fast lookup by class name.
 * Rules are indexed by their source classes (including additionalSourceClasses)
 * for O(1) lookup during analysis.
 *
 * Usage:
 *     $registry = new ForwardingChainRegistry();
 *     $registry->register(
 *         new ForwardingRule(sourceClass: Relation::class, ...),
 *         new ForwardingRule(sourceClass: Builder::class, ...),
 *     );
 *
 *     // During analysis:
 *     $rules = $registry->getRulesFor('Illuminate\Database\Eloquent\Relations\HasMany');
 *     // Returns the Relation rule (registered via additionalSourceClasses)
 */
/** @psalm-external-mutation-free */
final class ForwardingChainRegistry
{
    /** @var array<lowercase-string, list<ForwardingRule>> Rules indexed by lowercase source class */
    private array $rulesByClass = [];

    /** @var list<ForwardingRule> All registered rules (for introspection) */
    private array $allRules = [];

    /**
     * Register one or more forwarding rules.
     *
     * Each rule is indexed under its sourceClass AND all additionalSourceClasses.
     * A class can have multiple rules (they are tried in registration order).
     *
     * @psalm-external-mutation-free
     */
    public function register(ForwardingRule ...$rules): void
    {
        foreach ($rules as $rule) {
            $this->allRules[] = $rule;

            foreach ($rule->allSourceClasses() as $className) {
                $key = \strtolower($className);
                $this->rulesByClass[$key][] = $rule;
            }
        }
    }

    /**
     * Get all rules that apply to a given class.
     *
     * @return list<ForwardingRule> Rules in registration order, empty if none match.
     * @psalm-mutation-free
     */
    public function getRulesFor(string $className): array
    {
        return $this->rulesByClass[\strtolower($className)] ?? [];
    }

    /**
     * Get all class names that have at least one rule registered.
     *
     * Used by MethodForwardingHandler::getClassLikeNames() to tell Psalm which
     * classes this handler intercepts.
     *
     * @return list<string>
     * @psalm-mutation-free
     */
    public function getAllRegisteredClasses(): array
    {
        $classes = [];

        foreach ($this->allRules as $rule) {
            foreach ($rule->allSourceClasses() as $className) {
                $classes[] = $className;
            }
        }

        return \array_values(\array_unique($classes));
    }

    /**
     * Get all registered rules (for introspection/debugging).
     *
     * @return list<ForwardingRule>
     * @psalm-mutation-free
     * @psalm-api
     */
    public function getAllRules(): array
    {
        return $this->allRules;
    }

    /**
     * Check if any rules are registered for a given class.
     *
     * @psalm-mutation-free
     * @psalm-api
     */
    public function hasRulesFor(string $className): bool
    {
        return isset($this->rulesByClass[\strtolower($className)]);
    }

}
