<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Magic;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Magic\ForwardingChainRegistry;
use Psalm\LaravelPlugin\Handlers\Magic\ForwardingStyle;
use Psalm\LaravelPlugin\Handlers\Magic\LaravelForwardingConfig;

#[CoversClass(LaravelForwardingConfig::class)]
final class LaravelForwardingConfigTest extends TestCase
{
    private ForwardingChainRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = LaravelForwardingConfig::createRegistry();
    }

    #[Test]
    public function it_creates_a_non_empty_registry(): void
    {
        $this->assertNotSame([], $this->registry->getAllRules());
    }

    #[Test]
    public function it_registers_relation_forwarding_rule(): void
    {
        $rules = $this->registry->getRulesFor(Relation::class);

        $this->assertCount(1, $rules);
        $this->assertSame(ForwardingStyle::Decorated, $rules[0]->style);
        $this->assertSame([Builder::class, QueryBuilder::class], $rules[0]->searchClasses);
    }

    #[Test]
    public function it_registers_all_concrete_relation_subclasses(): void
    {
        // Spot-check: HasMany, BelongsTo, HasOne, MorphMany should all have the same rule
        foreach ([HasMany::class, BelongsTo::class, HasOne::class, MorphMany::class] as $relationClass) {
            $rules = $this->registry->getRulesFor($relationClass);
            $this->assertCount(1, $rules, "Expected 1 rule for {$relationClass}");
            $this->assertSame(ForwardingStyle::Decorated, $rules[0]->style);
        }
    }

    #[Test]
    public function relation_rule_has_correct_self_return_indicators(): void
    {
        $rules = $this->registry->getRulesFor(Relation::class);
        $indicators = $rules[0]->selfReturnIndicators;

        // Builder is a self-return indicator (where() returns Builder → Relation returns itself)
        $this->assertContains(Builder::class, $indicators);
        // 'static' is detected via TNamedObject::$is_static, not via selfReturnIndicators
        $this->assertNotContains('static', $indicators);
    }

    #[Test]
    public function relation_rule_does_not_indicate_query_builder_as_self_returning(): void
    {
        $rules = $this->registry->getRulesFor(Relation::class);
        $indicators = $rules[0]->selfReturnIndicators;

        // Query\Builder is NOT a self-return indicator — methods like toBase()
        // return Query\Builder intentionally (dropping to the lower layer).
        $this->assertNotContains(QueryBuilder::class, $indicators);
    }

    #[Test]
    public function relation_rule_has_intercept_mixin_enabled(): void
    {
        $rules = $this->registry->getRulesFor(Relation::class);

        // interceptMixin=true is required for the handler to register for Builder
        // and intercept @mixin-resolved calls from Relation callers.
        $this->assertTrue($rules[0]->interceptMixin);
    }

    #[Test]
    public function relation_rule_does_not_include_non_relation_classes(): void
    {
        // MorphPivot is in the Relations namespace but extends Model, not Relation.
        $rules = $this->registry->getRulesFor(\Illuminate\Database\Eloquent\Relations\MorphPivot::class);
        $this->assertSame([], $rules);
    }
}
