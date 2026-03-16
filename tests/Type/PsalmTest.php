<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Type;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPyh\PsalmTester\PsalmTester;

final class PsalmTest extends TestCase
{
    /** @var array<string, string> */
    private static array $batchResults = [];

    /** @var array<string, \PHPyh\PsalmTester\PsalmTest> */
    private static array $testData = [];

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        $tester = PsalmTester::create(
            defaultArguments: '--no-progress --no-diff --config=' . __DIR__ . '/psalm.xml',
        );

        $baseDir = self::baseDir();

        foreach (self::discoverPhptFiles($baseDir) as $absPath => $relPath) {
            self::$testData[$relPath] = \PHPyh\PsalmTester\PsalmTest::fromPhptFile($absPath);
        }

        self::$batchResults = $tester->runBatch(self::$testData);
    }

    /** @return \Generator<string, array{0: string}> */
    public static function providePhptFiles(): \Generator
    {
        $baseDir = self::baseDir();

        foreach (self::discoverPhptFiles($baseDir) as $relPath) {
            yield $relPath => [$relPath];
        }
    }

    #[DataProvider('providePhptFiles')]
    public function testPhptFiles(string $relPath): void
    {
        Assert::assertThat(
            self::$batchResults[$relPath],
            self::$testData[$relPath]->constraint,
        );
    }

    /** @psalm-pure */
    private static function baseDir(): string
    {
        return __DIR__ . \DIRECTORY_SEPARATOR . 'tests' . \DIRECTORY_SEPARATOR;
    }

    /** @return \Generator<string, string> absPath => relPath */
    private static function discoverPhptFiles(string $baseDir): \Generator
    {
        $dirItr = new \RecursiveDirectoryIterator($baseDir);
        $itr = new \RecursiveIteratorIterator($dirItr);
        $regItr = new \RegexIterator($itr, '/^.+\.phpt$/', \RegexIterator::GET_MATCH);

        foreach ($regItr as $file) {
            $filepath = $file[0];
            $relPath = \str_replace($baseDir, '', $filepath);
            yield $filepath => $relPath;
        }
    }
}
