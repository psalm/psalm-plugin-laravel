<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Pest;

/**
 * Answers which TestCase class Pest binds a test file's closures to, from `tests/Pest.php` plus the
 * file's own `uses()` / `pest()->extend()` calls, following `Pest\Repositories\TestRepository::make()`:
 * a file target matches by equality, a directory target by path prefix, traits never decide the
 * class, and more than one class for one file is a runtime `TestCaseAlreadyInUse`.
 *
 * `null` means "unknown": the handler then keeps Pest's own `@param-closure-this TestCall`.
 */
final class PestTestCaseResolver
{
    /** Pest's default when nothing in `uses()` / `pest()` applies to the file. */
    public const DEFAULT_TEST_CASE = 'PHPUnit\Framework\TestCase';

    /**
     * Parsed `Pest.php` per path; `null` = missing or unreadable.
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
    public static function resolve(string $pestFile, string $testFile, string $testContents, \Closure $isClass): ?string
    {
        if (\array_key_exists($testFile, self::$resolved)) {
            return self::$resolved[$testFile];
        }

        return self::$resolved[$testFile] = self::doResolve($pestFile, $testFile, $testContents, $isClass);
    }

    /** @param \Closure(string): ?bool $isClass */
    private static function doResolve(string $pestFile, string $testFile, string $testContents, \Closure $isClass): ?string
    {
        $inFile = PestUsesParser::parse($testFile, $testContents);
        if ($inFile === null) {
            return null;
        }

        $config = self::config($pestFile);
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

        // Without a readable Pest.php a directory-wide TestCase may exist that was not seen.
        return $config === null ? null : self::DEFAULT_TEST_CASE;
    }

    /** @return list<array{classes: list<string>, targets: list<string>}>|null */
    private static function config(string $pestFile): ?array
    {
        if (!\array_key_exists($pestFile, self::$configs)) {
            $contents = \is_file($pestFile) ? \file_get_contents($pestFile) : false;
            self::$configs[$pestFile] = $contents === false ? null : PestUsesParser::parse($pestFile, $contents);
        }

        return self::$configs[$pestFile];
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
