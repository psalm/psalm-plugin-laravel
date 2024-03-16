<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Type;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPyh\PsalmTester\PsalmTester;

use function basename;
use function glob;

final class PsalmTest extends TestCase
{
    private ?PsalmTester $psalmTester = null;

    /** @return \Generator<string, array{string}> */
    public static function providePhptFiles(): \Generator
    {
        $filePaths = glob(__DIR__.'/tests/*.phpt');

        foreach($filePaths as $filePath) {
            yield basename($filePath) => [$filePath];
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
