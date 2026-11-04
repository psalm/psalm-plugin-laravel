<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Stubs\StubFileFinder;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\CollectingProgress;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\InMemoryStubStreamWrapper;

#[CoversClass(StubFileFinder::class)]
final class StubFileFinderTest extends TestCase
{
    /** Inside psalm.phar the stubs live under phar://, where realpath() is false for every file. */
    #[Test]
    public function it_finds_stubs_in_a_directory_without_real_paths(): void
    {
        InMemoryStubStreamWrapper::register([
            'root/common/A.phpstub' => '<?php class A {}',
            'root/common/nested/B.phpstub' => '<?php class B {}',
            'root/common/readme.md' => 'not a stub',
        ]);

        try {
            $output = new CollectingProgress();
            $directory = InMemoryStubStreamWrapper::SCHEME . '://root/common';

            $this->assertFalse(\realpath($directory . '/A.phpstub'));
            $this->assertSame(
                [
                    InMemoryStubStreamWrapper::SCHEME . '://root/common/A.phpstub',
                    InMemoryStubStreamWrapper::SCHEME . '://root/common/nested/B.phpstub',
                ],
                StubFileFinder::findIn($directory, $output),
            );
            $this->assertSame([], $output->warnings);
        } finally {
            InMemoryStubStreamWrapper::unregister();
        }
    }

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
        yield 'patch dirs — includes only versions <= target' => [
            ['12', '12.20.0', '12.42.0', '13'],
            '12.30.0',
            ['12', '12.20.0'],
        ];

        yield 'exact version match is included' => [
            ['12.20.0'],
            '12.20.0',
            ['12.20.0'],
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
    }
}
