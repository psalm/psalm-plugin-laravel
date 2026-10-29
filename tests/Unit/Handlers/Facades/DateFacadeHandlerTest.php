<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Facades;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Facades\DateFacadeHandler;
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
    public function returns_null_when_no_carbon_atomic_is_present(): void
    {
        // Methods like `getLocale(): string` or `withTimeZone(): static` have no Carbon
        // atomic to rewrite — the handler defers to Psalm's own `@method` resolution.
        $result = DateFacadeHandler::swapCarbon(new Union([new TString()]), CarbonImmutable::class);

        $this->assertNull($result);
    }

    #[Test]
    public function a_date_method_is_mixed_when_the_configured_class_is_no_carbon(): void
    {
        // Declining would keep the facade's `Illuminate\Support\Carbon`, which is wrong for such an app.
        $result = DateFacadeHandler::swapCarbon(new Union([new TNamedObject(Carbon::class), new TNull()]), null);

        $this->assertNotNull($result);
        $this->assertTrue($result->isMixed());

        $this->assertNull(DateFacadeHandler::swapCarbon(new Union([new TString()]), null));
    }
}
