<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\DynamicWhereResolver;

#[CoversClass(DynamicWhereResolver::class)]
final class DynamicWhereResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        DynamicWhereResolver::reset();
    }

    #[Test]
    public function isDynamicWhereMethod_recognises_prefixed_methods(): void
    {
        $this->assertTrue(DynamicWhereResolver::isDynamicWhereMethod('whereid'));
        $this->assertTrue(DynamicWhereResolver::isDynamicWhereMethod('wherefirstnameandlastname'));

        $this->assertFalse(DynamicWhereResolver::isDynamicWhereMethod('where'));
        $this->assertFalse(DynamicWhereResolver::isDynamicWhereMethod('orWhereTitle'));
    }

    #[Test]
    public function reset_clears_enable_flag_so_config_flip_takes_effect(): void
    {
        DynamicWhereResolver::enable();
        $this->assertTrue(DynamicWhereResolver::isEnabled());

        DynamicWhereResolver::reset();

        // A subsequent bootstrap that does NOT re-call enable() must observe the flag
        // back at its default state. Without this, a previously-enabled run would leak
        // into a `<resolveDynamicWhereClauses value="false" />` configuration in the
        // same process.
        $this->assertFalse(
            DynamicWhereResolver::isEnabled(),
            'reset() must clear the enable flag so a true->false config flip is honoured on the next bootstrap.',
        );
    }

    #[Test]
    public function variadicMixedParams_returns_single_variadic_mixed_param(): void
    {
        $params = DynamicWhereResolver::variadicMixedParams();

        $this->assertCount(1, $params);
        $this->assertSame('args', $params[0]->name);
        $this->assertTrue($params[0]->is_variadic);
        $this->assertNotNull($params[0]->type);
        $this->assertTrue($params[0]->type->isMixed());
    }
}
