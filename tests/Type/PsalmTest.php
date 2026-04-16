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

    /** @var array<string, string> relPath => skip reason */
    private static array $skipReasons = [];

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        $tester = PsalmTester::create(
            defaultArguments: '--no-progress --no-diff --config=' . __DIR__ . '/psalm.xml',
        );

        $baseDir = self::baseDir();

        foreach (self::discoverPhptFiles($baseDir) as $absPath => $relPath) {
            $skipReason = self::evaluateSkipIf($absPath);

            if ($skipReason !== null) {
                self::$skipReasons[$relPath] = $skipReason;
                continue;
            }

            // psalm-tester throws on unknown sections (including --SKIPIF--), so strip it
            // before passing to fromPhptFile(). The temp file can be unlinked immediately
            // because fromPhptFile() reads and parses the content on the spot.
            $testerFile = self::stripSkipIfSection($absPath);
            self::$testData[$relPath] = \PHPyh\PsalmTester\PsalmTest::fromPhptFile($testerFile);
            if ($testerFile !== $absPath) {
                \unlink($testerFile);
            }
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
        if (isset(self::$skipReasons[$relPath])) {
            $this->markTestSkipped(self::$skipReasons[$relPath]);
        }

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

    /**
     * Evaluate the --SKIPIF-- section of a PHPT file.
     *
     * Returns the skip reason if the section says to skip, or null otherwise.
     * The SKIPIF section contains PHP code (starting with <?php) that echoes
     * "skip <reason>" if the test should be skipped.
     */
    private static function evaluateSkipIf(string $phptFile): ?string
    {
        $content = \file_get_contents($phptFile);

        if ($content === false) {
            return null;
        }

        if (!\preg_match('/^--SKIPIF--\n(.*?)\n--/ms', $content, $matches)) {
            return null; // No SKIPIF section
        }

        $skipifCode = $matches[1];
        $tempFile = \tempnam(\sys_get_temp_dir(), 'psalm_skipif_');

        if ($tempFile === false) {
            return null;
        }

        \file_put_contents($tempFile, $skipifCode);

        \ob_start();

        try {
            // Use a static closure to avoid leaking $this, and require the temp file
            // so that the <?php tag in SKIPIF code is handled correctly.
            (static function (string $file): void {
                require $file;
            })($tempFile);
        } finally {
            \unlink($tempFile);
        }

        $output = \trim(\ob_get_clean() ?: '');

        if (\stripos($output, 'skip') === 0) {
            return $output;
        }

        return null;
    }

    /**
     * Return the path to pass to psalm-tester: the original file if it has no --SKIPIF-- section,
     * or a temporary file with the SKIPIF section removed (psalm-tester throws on unknown sections).
     *
     * The caller is responsible for unlinking the returned path when it differs from $phptFile.
     */
    private static function stripSkipIfSection(string $phptFile): string
    {
        $content = \file_get_contents($phptFile);

        if ($content === false) {
            return $phptFile;
        }

        $stripped = \preg_replace('/^--SKIPIF--\n.*?\n(?=--)/ms', '', $content);

        if ($stripped === null || $stripped === $content) {
            return $phptFile; // No SKIPIF section, use original
        }

        $tempFile = \tempnam(\sys_get_temp_dir(), 'psalm_phpt_');

        if ($tempFile === false) {
            return $phptFile;
        }

        \file_put_contents($tempFile, $stripped);

        return $tempFile;
    }
}
