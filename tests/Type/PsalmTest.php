<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Type;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPyh\PsalmTester\PsalmTester;

use function str_replace;

final class PsalmTest extends TestCase
{
    private ?PsalmTester $psalmTester = null;

    /** @return \Generator<string, array{0: string}> */
    public static function providePhptFiles(): \Generator
    {
        $baseDir = __DIR__ . \DIRECTORY_SEPARATOR . 'tests' . \DIRECTORY_SEPARATOR;
        $testExtension = 'phpt';

        $dirItr = new \RecursiveDirectoryIterator($baseDir);
        $itr = new \RecursiveIteratorIterator($dirItr);
        $regItr = new \RegexIterator($itr, "/^.+.{$testExtension}\$/", \RegexIterator::GET_MATCH);

        foreach ($regItr as $file) {
            $filepath = $file[0];
            $relativeFilepath = str_replace($baseDir, '', $filepath);
            yield $relativeFilepath => [$filepath];
        }
    }

    #[DataProvider('providePhptFiles')]
    public function testPhptFiles(string $phptFilepath): void
    {
        $this->psalmTester ??= PsalmTester::create(
            defaultArguments: '--no-progress --no-diff --config=' . __DIR__ . '/psalm.xml',
        );
        $this->psalmTester->test(\PHPyh\PsalmTester\PsalmTest::fromPhptFile($phptFilepath));
    }
}
