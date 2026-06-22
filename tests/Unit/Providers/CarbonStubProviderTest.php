<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Providers;

use Composer\Semver\Semver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Providers\CarbonStubProvider;

#[CoversClass(CarbonStubProvider::class)]
final class CarbonStubProviderTest extends TestCase
{
    /**
     * Locks the Carbon-major boundaries that decide which stub set the provider registers.
     * The CI matrix only ever resolves Carbon 3, so without this the Carbon-2 and <3.12 branches
     * (the #1142 fix) are never exercised by an automated check. `Semver::satisfies` here mirrors
     * the `InstalledVersions::satisfies` calls in {@see CarbonStubProvider::register()} against the
     * same constraint constants.
     *
     * @see https://github.com/psalm/psalm-plugin-laravel/issues/1142
     */
    #[Test]
    #[DataProvider('carbonVersionProvider')]
    public function it_gates_stub_sets_by_carbon_major(
        string $carbonVersion,
        bool $expectedIsCarbon3,
        bool $expectedDualPurposeNarrowings,
    ): void {
        $this->assertSame($expectedIsCarbon3, Semver::satisfies($carbonVersion, CarbonStubProvider::CARBON_3_CONSTRAINT), 'CarbonPeriod / DatePeriodBase stub selection');
        $this->assertSame($expectedDualPurposeNarrowings, Semver::satisfies($carbonVersion, CarbonStubProvider::DUAL_PURPOSE_NARROWINGS_CONSTRAINT), 'dual-purpose narrowing stub selection');
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function carbonVersionProvider(): iterable
    {
        // Carbon 2: Iterator-shaped CarbonPeriod, no DatePeriodBase, no WeekDay enum.
        yield 'Carbon 2.72 (Laravel 11 floor)' => ['2.72.0', false, false];
        yield 'Carbon 2.73 (latest 2.x)' => ['2.73.0', false, false];

        // Carbon 3.0-3.11: DatePeriodBase shape plus the dual-purpose narrowings.
        yield 'Carbon 3.0 (enums + DatePeriodBase land)' => ['3.0.0', true, true];
        yield 'Carbon 3.11.4 (last before the 3.12 conditional)' => ['3.11.4', true, true];

        // Carbon 3.12+: narrowings superseded by Carbon's own inline conditional (#1059).
        yield 'Carbon 3.12 (narrowings superseded)' => ['3.12.0', true, false];
        yield 'Carbon 3.13 (current)' => ['3.13.0', true, false];

        // Hypothetical Carbon 4 is outside the supported matrix, but documents that >=3.0 keeps
        // selecting the Carbon-3 shape (matches the prior unconditional behavior).
        yield 'Carbon 4.0 (out of matrix, Carbon-3 shape)' => ['4.0.0', true, false];
    }
}
