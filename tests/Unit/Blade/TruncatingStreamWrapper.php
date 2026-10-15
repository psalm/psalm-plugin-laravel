<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

/**
 * Serves a real directory under `truncating://<absolute path>`, except that reading a file whose
 * path ends in {@see self::$failingSuffix} yields a few bytes and then fails, the shape of a
 * mid-read I/O error that `file_get_contents()` turns into a partial string rather than `false`.
 *
 * Method names follow PHP's stream wrapper protocol.
 */
final class TruncatingStreamWrapper
{
    public const SCHEME = 'truncating';

    public static string $failingSuffix = '';

    /** @var resource|null */
    public $context;

    /** @var resource|null */
    private $handle;

    private bool $failing = false;

    private bool $served = false;

    public static function register(): void
    {
        if (!\in_array(self::SCHEME, \stream_get_wrappers(), true)) {
            \stream_wrapper_register(self::SCHEME, self::class);
        }
    }

    public static function unregister(): void
    {
        if (\in_array(self::SCHEME, \stream_get_wrappers(), true)) {
            \stream_wrapper_unregister(self::SCHEME);
        }
    }

    /** @return array<array-key, int>|false */
    public function url_stat(string $path): array|false
    {
        return @\stat($this->real($path));
    }

    public function dir_opendir(string $path): bool
    {
        $handle = @\opendir($this->real($path));
        $this->handle = $handle !== false ? $handle : null;

        return $handle !== false;
    }

    public function dir_readdir(): string|false
    {
        return $this->handle !== null ? \readdir($this->handle) : false;
    }

    public function dir_rewinddir(): bool
    {
        if ($this->handle !== null) {
            \rewinddir($this->handle);
        }

        return true;
    }

    public function dir_closedir(): bool
    {
        if ($this->handle !== null) {
            \closedir($this->handle);
        }

        return true;
    }

    public function stream_open(string $path, string $mode): bool
    {
        $handle = @\fopen($this->real($path), $mode);
        $this->handle = $handle !== false ? $handle : null;

        $this->failing = self::$failingSuffix !== '' && \str_ends_with($path, self::$failingSuffix);

        return $handle !== false;
    }

    public function stream_read(int $count): string|false
    {
        if ($this->handle === null || ($this->failing && $this->served)) {
            return false;
        }

        $this->served = true;

        return \fread($this->handle, $this->failing ? 3 : \max(1, $count));
    }

    public function stream_eof(): bool
    {
        return $this->handle === null || (!$this->failing && \feof($this->handle));
    }

    /** @return array<array-key, int>|false */
    public function stream_stat(): array|false
    {
        return $this->handle !== null ? \fstat($this->handle) : false;
    }

    public function stream_close(): void
    {
        if ($this->handle !== null) {
            \fclose($this->handle);
        }
    }

    private function real(string $url): string
    {
        return \substr($url, \strlen(self::SCHEME . '://'));
    }
}
