<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Pest;

/**
 * Answers which TestCase class Pest binds a test file's closures to, from the test directory's boot
 * files (`Pest.php`, `Helpers.php`, `Expectations.php` and the `Helpers/`, `Expectations/` trees, as
 * `Pest\Bootstrappers\BootFiles` loads them) plus the file's own `uses()` / `pest()->extend()` calls,
 * following `Pest\Repositories\TestRepository::make()`:
 * a file target matches by equality, a directory target by path prefix, traits never decide the
 * class, and more than one class for one file is a runtime `TestCaseAlreadyInUse`.
 *
 * `null` means "unknown": the handler then keeps Pest's own `@param-closure-this TestCall`.
 * PHPUnit's default is only claimed inside the test directory those files govern (a monorepo
 * package's own tests may have their own `Pest.php`).
 */
final class PestTestCaseResolver
{
    /** Pest's default when nothing in `uses()` / `pest()` applies to the file. */
    public const DEFAULT_TEST_CASE = 'PHPUnit\Framework\TestCase';

    /** `Pest\Bootstrappers\BootFiles::STRUCTURE` (pest v4.7.0): files, or directories loaded recursively. */
    private const BOOT_FILES = ['Expectations', 'Expectations.php', 'Helpers', 'Helpers.php', 'Pest.php'];

    /**
     * Parsed boot files per test directory; `null` = no `Pest.php`, or a file that is unreadable.
     *
     * @var array<string, list<array{classes: list<string>, targets: list<string>}>|null>
     */
    private static array $configs = [];

    /** @var array<string, ?string> */
    private static array $resolved = [];

    /**
     * @param \Closure(string): ?bool $isClass true for a class, false for a trait or interface,
     *                                         null when the name is unknown
     */
    public static function resolve(string $testsDir, string $testFile, string $testContents, \Closure $isClass): ?string
    {
        if (\array_key_exists($testFile, self::$resolved)) {
            return self::$resolved[$testFile];
        }

        return self::$resolved[$testFile] = self::doResolve($testsDir, $testFile, $testContents, $isClass);
    }

    /** @param \Closure(string): ?bool $isClass */
    private static function doResolve(string $testsDir, string $testFile, string $testContents, \Closure $isClass): ?string
    {
        $inFile = PestUsesParser::parse($testFile, $testContents);
        if ($inFile === null) {
            return null;
        }

        $config = self::config($testsDir);
        $realTestFile = PestUsesParser::realpath($testFile);

        $candidates = [];
        foreach ([...$inFile, ...$config ?? []] as $entry) {
            if (!self::targets($entry['targets'], $realTestFile)) {
                continue;
            }

            foreach ($entry['classes'] as $class) {
                $kind = $isClass($class);
                if ($kind === null) {
                    return null;
                }

                if ($kind) {
                    $candidates[\strtolower($class)] = $class;
                }
            }
        }

        if (\count($candidates) > 1) {
            return null;
        }

        if ($candidates !== []) {
            return \reset($candidates);
        }

        // Without readable boot files a directory-wide TestCase may exist that was not seen.
        return $config !== null && self::targets([PestUsesParser::realpath($testsDir)], $realTestFile)
            ? self::DEFAULT_TEST_CASE
            : null;
    }

    /** @return list<array{classes: list<string>, targets: list<string>}>|null */
    private static function config(string $testsDir): ?array
    {
        if (\array_key_exists($testsDir, self::$configs)) {
            return self::$configs[$testsDir];
        }

        if (!\is_file($testsDir . \DIRECTORY_SEPARATOR . 'Pest.php')) {
            return self::$configs[$testsDir] = null;
        }

        $entries = [];
        foreach (self::bootFiles($testsDir) as $file) {
            $contents = \file_get_contents($file);
            $parsed = $contents === false ? null : PestUsesParser::parse($file, $contents);
            if ($parsed === null) {
                return self::$configs[$testsDir] = null;
            }

            \array_push($entries, ...$parsed);
        }

        return self::$configs[$testsDir] = $entries;
    }

    /** @return list<string> */
    private static function bootFiles(string $testsDir): array
    {
        $files = [];
        foreach (self::BOOT_FILES as $name) {
            $path = $testsDir . \DIRECTORY_SEPARATOR . $name;
            if (\is_file($path)) {
                $files[] = $path;
            } elseif (\is_dir($path)) {
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
                /** @var \SplFileInfo $file */
                foreach ($iterator as $file) {
                    if (\str_ends_with($file->getPathname(), '.php')) {
                        $files[] = $file->getPathname();
                    }
                }
            }
        }

        return $files;
    }

    /** @param list<string> $targets */
    private static function targets(array $targets, string $file): bool
    {
        foreach ($targets as $target) {
            if ($target === $file || \str_starts_with($file, \rtrim($target, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    /** @psalm-external-mutation-free */
    public static function reset(): void
    {
        self::$configs = [];
        self::$resolved = [];
    }
}
