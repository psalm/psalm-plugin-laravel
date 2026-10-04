<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Stubs\StubFileFinder;

#[CoversClass(StubFileFinder::class)]
final class StubFileFinderTest extends TestCase
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
        $this->assertSame($expected, StubFileFinder::filterVersionDirectories($candidates, $targetVersion));
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

    /** @param list<string> $expected */
    #[Test]
    #[DataProvider('declaredClassLikesProvider')]
    public function it_lists_the_class_likes_a_stub_declares(string $contents, array $expected): void
    {
        $file = \tempnam(\sys_get_temp_dir(), 'stub-finder-');
        $this->assertIsString($file);

        try {
            \file_put_contents($file, $contents);
            $this->assertSame($expected, StubFileFinder::declaredClassLikes($file));
        } finally {
            \unlink($file);
        }
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function declaredClassLikesProvider(): iterable
    {
        yield 'every declaration kind, in file order, with modifiers' => [
            <<<'PHP'
                <?php
                namespace Illuminate\Support;

                interface Arrayable {}
                trait Macroable {}
                enum Status: string { case On = 'on'; }
                final class Js {}
                abstract readonly class Base {}
                PHP,
            [
                'Illuminate\Support\Arrayable',
                'Illuminate\Support\Macroable',
                'Illuminate\Support\Status',
                'Illuminate\Support\Js',
                'Illuminate\Support\Base',
            ],
        ];

        yield 'single-segment namespace' => [
            "<?php\nnamespace Carbon;\n\nclass CarbonPeriod {}\n",
            ['Carbon\CarbonPeriod'],
        ];

        yield 'global namespace' => [
            "<?php\n\nclass NullObject {}\n",
            ['NullObject'],
        ];

        yield 'class constants, anonymous classes and comments are not declarations' => [
            <<<'PHP'
                <?php
                namespace App;

                /** Mentions class Fake and interface Ghost in prose. */
                // class AlsoFake {}
                class Real
                {
                    /** @return class-string */
                    public function a(): string { return self::class; }
                    public function b(): object { return new class {}; }
                    public function c(): object { return new class () extends Real {}; }
                }
                PHP,
            ['App\Real'],
        ];

        yield 'function-only stub declares nothing' => [
            "<?php\n\n/** @return string */\nfunction e(mixed \$value) {}\n",
            [],
        ];
    }
}
