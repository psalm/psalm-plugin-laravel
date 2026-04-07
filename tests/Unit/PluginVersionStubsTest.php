<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Plugin;

#[CoversClass(Plugin::class)]
final class PluginVersionStubsTest extends TestCase
{
    /**
     * @param list<string> $candidates
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('versionFilteringProvider')]
    public function it_filters_and_sorts_version_directories(
        array $candidates,
        string $targetVersion,
        array $expected,
    ): void {
        $this->assertSame($expected, Plugin::filterVersionDirectories($candidates, $targetVersion));
    }

    /** @return iterable<string, array{list<string>, string, list<string>}> */
    public static function versionFilteringProvider(): iterable
    {
        yield 'major-only dirs — includes matching major' => [
            ['12', '13'],
            '12.5.0',
            ['12'],
        ];

        yield 'major-only dirs — includes both when on higher major' => [
            ['12', '13'],
            '13.1.0',
            ['12', '13'],
        ];

        yield 'patch dirs — includes only versions <= target' => [
            ['12', '12.20.0', '12.42.0', '13'],
            '12.30.0',
            ['12', '12.20.0'],
        ];

        yield 'patch dirs — includes all matching versions' => [
            ['12', '12.20.0', '12.42.0'],
            '12.50.0',
            ['12', '12.20.0', '12.42.0'],
        ];

        yield 'exact version match is included' => [
            ['12.20.0'],
            '12.20.0',
            ['12.20.0'],
        ];

        yield 'empty candidates returns empty' => [
            [],
            '12.0.0',
            [],
        ];

        yield 'no matching versions returns empty' => [
            ['13', '13.5.0'],
            '12.99.0',
            [],
        ];

        yield 'sorts ascending by version, not lexicographically' => [
            ['12.9.0', '12.20.0', '12'],
            '12.20.0',
            ['12', '12.9.0', '12.20.0'],
        ];

        yield 'mixed major and patch versions' => [
            ['11', '12', '12.20.0', '12.42.0', '13', '13.5.0'],
            '13.2.0',
            ['11', '12', '12.20.0', '12.42.0', '13'],
        ];
    }
}
