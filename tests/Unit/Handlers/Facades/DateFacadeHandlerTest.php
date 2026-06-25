<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Facades;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Facades\DateFacadeHandler;
use Psalm\Type\Atomic\TFalse;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Union;

/**
 * Unit tests for the Carbon -> configured-class substitution.
 *
 * The full handler depends on Psalm's Codebase (booted app, pseudo-method storage),
 * which the type test {@see tests/Type/tests/Facades/DateFacadeStaticCallTest.phpt}
 * exercises. The substitution is pure and is the part that varies with the configured
 * date class, so it is covered here against a swapped class (CarbonImmutable) — a case
 * the type test cannot trigger because the handler reads the booted app, not the
 * analysed code.
 */
#[CoversClass(DateFacadeHandler::class)]
final class DateFacadeHandlerTest extends TestCase
{
    #[Test]
    public function swaps_carbon_for_the_configured_class(): void
    {
        $result = DateFacadeHandler::swapCarbon(
            new Union([new TNamedObject(Carbon::class)]),
            CarbonImmutable::class,
        );

        $this->assertNotNull($result);
        $this->assertSame(CarbonImmutable::class, $result->getId());
    }

    #[Test]
    public function preserves_nullability_through_the_swap(): void
    {
        // `create()` / `getTestNow()` / `make()` are `Carbon|null` on Laravel 12+.
        $result = DateFacadeHandler::swapCarbon(
            new Union([new TNamedObject(Carbon::class), new TNull()]),
            CarbonImmutable::class,
        );

        $this->assertNotNull($result);
        $this->assertTrue($result->isNullable());
        $this->assertTrue($result->hasObjectType());
        $this->assertSame(CarbonImmutable::class . '|null', $result->getId());
    }

    #[Test]
    public function preserves_the_false_member_through_the_swap(): void
    {
        // Laravel 11 typed several `create*` methods `Carbon|false`; reading the declared
        // type (rather than a hardcoded list) keeps that member correct after the swap.
        $result = DateFacadeHandler::swapCarbon(
            new Union([new TNamedObject(Carbon::class), new TFalse()]),
            CarbonImmutable::class,
        );

        $this->assertNotNull($result);
        $this->assertSame(CarbonImmutable::class . '|false', $result->getId());
    }

    #[Test]
    public function returns_null_when_no_carbon_atomic_is_present(): void
    {
        // Methods like `getLocale(): string` or `withTimeZone(): static` have no Carbon
        // atomic to rewrite — the handler defers to Psalm's own `@method` resolution.
        $result = DateFacadeHandler::swapCarbon(new Union([new TString()]), CarbonImmutable::class);

        $this->assertNull($result);
    }

    #[Test]
    public function identity_swap_reproduces_the_default_carbon_type(): void
    {
        // Default app: configured class is Carbon, so the swap is the identity. The handler
        // still produces the type (no short-circuit), which is what the type test asserts.
        $result = DateFacadeHandler::swapCarbon(
            new Union([new TNamedObject(Carbon::class), new TNull()]),
            Carbon::class,
        );

        $this->assertNotNull($result);
        $this->assertSame(Carbon::class . '|null', $result->getId());
    }
}
