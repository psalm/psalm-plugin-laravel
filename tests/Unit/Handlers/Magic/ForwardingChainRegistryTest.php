<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Magic;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Magic\ForwardingChainRegistry;
use Psalm\LaravelPlugin\Handlers\Magic\ForwardingRule;
use Psalm\LaravelPlugin\Handlers\Magic\ForwardingStyle;

#[CoversClass(ForwardingChainRegistry::class)]
final class ForwardingChainRegistryTest extends TestCase
{
    #[Test]
    public function it_returns_empty_for_unknown_class(): void
    {
        $registry = new ForwardingChainRegistry();
        $this->assertSame([], $registry->getRulesFor('NonExistent\\Class'));
    }

    #[Test]
    public function it_registers_and_retrieves_rules_by_source_class(): void
    {
        $registry = new ForwardingChainRegistry();
        $rule = new ForwardingRule(
            sourceClass: 'App\\SourceClass',
            searchClasses: ['App\\TargetClass'],
            style: ForwardingStyle::Decorated,
        );

        $registry->register($rule);

        $this->assertSame([$rule], $registry->getRulesFor('App\\SourceClass'));
    }

    #[Test]
    public function it_retrieves_rules_by_additional_source_classes(): void
    {
        $registry = new ForwardingChainRegistry();
        $rule = new ForwardingRule(
            sourceClass: 'App\\Base',
            searchClasses: ['App\\Target'],
            style: ForwardingStyle::AlwaysSelf,
            additionalSourceClasses: ['App\\SubA', 'App\\SubB'],
        );

        $registry->register($rule);

        // All three classes should return the same rule
        $this->assertSame([$rule], $registry->getRulesFor('App\\Base'));
        $this->assertSame([$rule], $registry->getRulesFor('App\\SubA'));
        $this->assertSame([$rule], $registry->getRulesFor('App\\SubB'));
    }

    #[Test]
    public function it_is_case_insensitive_for_lookup(): void
    {
        $registry = new ForwardingChainRegistry();
        $rule = new ForwardingRule(
            sourceClass: 'App\\MyClass',
            searchClasses: ['App\\Target'],
            style: ForwardingStyle::Passthrough,
        );

        $registry->register($rule);

        $this->assertSame([$rule], $registry->getRulesFor('app\\myclass'));
        $this->assertSame([$rule], $registry->getRulesFor('APP\\MYCLASS'));
    }

    #[Test]
    public function it_returns_multiple_rules_for_same_class(): void
    {
        $registry = new ForwardingChainRegistry();
        $rule1 = new ForwardingRule(
            sourceClass: 'App\\Source',
            searchClasses: ['App\\Target1'],
            style: ForwardingStyle::Decorated,
        );
        $rule2 = new ForwardingRule(
            sourceClass: 'App\\Source',
            searchClasses: ['App\\Target2'],
            style: ForwardingStyle::AlwaysSelf,
        );

        $registry->register($rule1, $rule2);

        $rules = $registry->getRulesFor('App\\Source');
        $this->assertCount(2, $rules);
        $this->assertSame($rule1, $rules[0]);
        $this->assertSame($rule2, $rules[1]);
    }

    #[Test]
    public function it_returns_all_registered_classes(): void
    {
        $registry = new ForwardingChainRegistry();
        $registry->register(
            new ForwardingRule(
                sourceClass: 'App\\Relation',
                searchClasses: ['App\\Builder'],
                style: ForwardingStyle::Decorated,
                additionalSourceClasses: ['App\\HasMany', 'App\\BelongsTo'],
            ),
            new ForwardingRule(
                sourceClass: 'App\\Builder',
                searchClasses: ['App\\QueryBuilder'],
                style: ForwardingStyle::AlwaysSelf,
            ),
        );

        $classes = $registry->getAllRegisteredClasses();
        \sort($classes);

        $this->assertSame([
            'App\\BelongsTo',
            'App\\Builder',
            'App\\HasMany',
            'App\\Relation',
        ], $classes);
    }

    #[Test]
    public function it_deduplicates_registered_classes(): void
    {
        $registry = new ForwardingChainRegistry();

        // App\\Builder appears as source in one rule and in additionalSourceClasses of another
        $registry->register(
            new ForwardingRule(
                sourceClass: 'App\\Relation',
                searchClasses: ['App\\Builder'],
                style: ForwardingStyle::Decorated,
                additionalSourceClasses: ['App\\Builder'],
            ),
        );

        $classes = $registry->getAllRegisteredClasses();
        $this->assertCount(2, $classes);
    }

    #[Test]
    public function it_checks_if_rules_exist_for_class(): void
    {
        $registry = new ForwardingChainRegistry();
        $registry->register(
            new ForwardingRule(
                sourceClass: 'App\\Source',
                searchClasses: ['App\\Target'],
                style: ForwardingStyle::Passthrough,
            ),
        );

        $this->assertTrue($registry->hasRulesFor('App\\Source'));
        $this->assertFalse($registry->hasRulesFor('App\\Unknown'));
    }

    #[Test]
    public function it_returns_all_rules_for_introspection(): void
    {
        $registry = new ForwardingChainRegistry();
        $rule1 = new ForwardingRule(
            sourceClass: 'A',
            searchClasses: ['B'],
            style: ForwardingStyle::Decorated,
        );
        $rule2 = new ForwardingRule(
            sourceClass: 'C',
            searchClasses: ['D'],
            style: ForwardingStyle::AlwaysSelf,
        );

        $registry->register($rule1, $rule2);

        $this->assertSame([$rule1, $rule2], $registry->getAllRules());
    }
}
