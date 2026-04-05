<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Magic;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Magic\ForwardingRule;

#[CoversClass(ForwardingRule::class)]
final class ForwardingRuleTest extends TestCase
{
    #[Test]
    public function allSourceClasses_includes_source_and_additional(): void
    {
        $rule = new ForwardingRule(
            sourceClass: 'App\\Base',
            searchClasses: ['App\\Target'],
            additionalSourceClasses: ['App\\Sub1', 'App\\Sub2'],
        );

        $this->assertSame(['App\\Base', 'App\\Sub1', 'App\\Sub2'], $rule->allSourceClasses());
    }

    #[Test]
    public function allSourceClasses_with_no_additional(): void
    {
        $rule = new ForwardingRule(
            sourceClass: 'App\\Base',
            searchClasses: ['App\\Target'],
        );

        $this->assertSame(['App\\Base'], $rule->allSourceClasses());
    }
}
