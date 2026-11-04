<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures;

/**
 * Read-only in-memory directory tree behind a `stubfinder-test://` URL scheme.
 *
 * Paths on a user stream wrapper have no realpath(), exactly like paths inside a phar, so this
 * reproduces the phar condition without building an archive. Implements only what is_dir()
 * and RecursiveDirectoryIterator need.
 *
 * @internal
 */
final class InMemoryStubStreamWrapper
{
    public const SCHEME = 'stubfinder-test';

    /** @var array<string, string> file path relative to the scheme root => contents */
    private static array $files = [];

    /** @var resource|null set by PHP when the wrapper is instantiated */
    public $context;

    /** @var list<string> */
    private array $entries = [];

    private int $position = 0;

    /** @param array<string, string> $files file path relative to the scheme root => contents */
    public static function register(array $files): void
    {
        self::$files = $files;

        if (!\stream_wrapper_register(self::SCHEME, self::class)) {
            throw new \RuntimeException('Stream wrapper scheme "' . self::SCHEME . '" is already registered');
        }
    }

    public static function unregister(): void
    {
        \stream_wrapper_unregister(self::SCHEME);
        self::$files = [];
    }

    /** @return array<string, int>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        $relative = self::relative($path);

        if (isset(self::$files[$relative])) {
            return ['mode' => 0100644, 'size' => \strlen(self::$files[$relative])];
        }

        if ($this->childNames($relative) !== []) {
            return ['mode' => 040755, 'size' => 0];
        }

        return false;
    }

    public function dir_opendir(string $path, int $options): bool
    {
        $this->entries = $this->childNames(self::relative($path));
        $this->position = 0;

        return true;
    }

    public function dir_readdir(): string|false
    {
        return $this->entries[$this->position++] ?? false;
    }

    public function dir_rewinddir(): bool
    {
        $this->position = 0;

        return true;
    }

    public function dir_closedir(): bool
    {
        $this->entries = [];

        return true;
    }

    private static function relative(string $url): string
    {
        return \trim(\substr($url, \strlen(self::SCHEME . '://')), '/');
    }

    /** @return list<string> sorted names of the direct children of the directory */
    private function childNames(string $directory): array
    {
        $prefix = $directory === '' ? '' : $directory . '/';
        $names = [];

        foreach (\array_keys(self::$files) as $file) {
            if (!\str_starts_with($file, $prefix)) {
                continue;
            }

            $names[\explode('/', \substr($file, \strlen($prefix)), 2)[0]] = true;
        }

        $names = \array_map(\strval(...), \array_keys($names));
        \sort($names);

        return $names;
    }
}
