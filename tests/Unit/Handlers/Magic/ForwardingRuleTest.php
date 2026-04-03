<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Magic;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Magic\ForwardingRule;
use Psalm\LaravelPlugin\Handlers\Magic\ForwardingStyle;

#[CoversClass(ForwardingRule::class)]
final class ForwardingRuleTest extends TestCase
{
    #[Test]
    public function it_returns_all_source_classes(): void
    {
        $rule = new ForwardingRule(
            sourceClass: 'App\\Base',
            searchClasses: ['App\\Target'],
            style: ForwardingStyle::Decorated,
            additionalSourceClasses: ['App\\SubA', 'App\\SubB'],
        );

        $this->assertSame(['App\\Base', 'App\\SubA', 'App\\SubB'], $rule->allSourceClasses());
    }

    #[Test]
    public function it_returns_only_source_class_when_no_additional(): void
    {
        $rule = new ForwardingRule(
            sourceClass: 'App\\Base',
            searchClasses: ['App\\Target'],
            style: ForwardingStyle::AlwaysSelf,
        );

        $this->assertSame(['App\\Base'], $rule->allSourceClasses());
    }

    #[Test]
    public function it_preserves_all_properties(): void
    {
        $rule = new ForwardingRule(
            sourceClass: 'App\\Source',
            searchClasses: ['App\\T1', 'App\\T2'],
            style: ForwardingStyle::Passthrough,
            selfReturnIndicators: ['App\\T1', 'static'],
            additionalSourceClasses: ['App\\Extra'],
            description: 'test rule',
        );

        $this->assertSame('App\\Source', $rule->sourceClass);
        $this->assertSame(['App\\T1', 'App\\T2'], $rule->searchClasses);
        $this->assertSame(ForwardingStyle::Passthrough, $rule->style);
        $this->assertSame(['App\\T1', 'static'], $rule->selfReturnIndicators);
        $this->assertSame(['App\\Extra'], $rule->additionalSourceClasses);
        $this->assertSame('test rule', $rule->description);
    }
}
