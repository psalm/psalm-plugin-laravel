<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Filesystem;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psalm\LaravelPlugin\Providers\ConfigRepositoryProvider;

/**
 * Reads `config/filesystems.php` to determine which disk maps to which driver.
 * The driver name is then matched against Laravel's built-in `createXDriver()`
 * methods to decide whether the disk returns a {@see \Illuminate\Contracts\Filesystem\Cloud}
 * (ships `url()`) or the base {@see \Illuminate\Contracts\Filesystem\Filesystem}
 * (file-only, does NOT declare `url()`).
 *
 * Custom drivers registered via `Storage::extend('foo', ...)` may also return
 * `Cloud`, but static analysis cannot peek inside the registered closure to
 * tell. We only narrow for built-ins (currently `'s3'`), which keeps us sound:
 * a custom cloud driver loses the narrowing but no file-only driver is ever
 * mis-narrowed to Cloud (which would silence a real `url()` runtime error).
 *
 * Mirrors the pattern of {@see \Psalm\LaravelPlugin\Handlers\Auth\AuthConfigAnalyzer}:
 * single per-process instance, lazy singleton, immutable wrap of the booted
 * Laravel app's config repository.
 *
 * @internal
 */
final class FilesystemConfigAnalyzer
{
    private static ?FilesystemConfigAnalyzer $instance = null;

    private ?string $default_disk_cache = null;

    private bool $default_disk_loaded = false;

    /** @var array<string, string|null> */
    private array $driver_cache = [];

    /** @psalm-mutation-free */
    private function __construct(private readonly ConfigRepository $config) {}

    public static function instance(): self
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self(ConfigRepositoryProvider::get());
        }

        return self::$instance;
    }

    public function getDefaultDisk(): ?string
    {
        if (!$this->default_disk_loaded) {
            $disk = $this->config->get('filesystems.default');
            $this->default_disk_cache = \is_string($disk) ? $disk : null;
            $this->default_disk_loaded = true;
        }

        return $this->default_disk_cache;
    }

    /**
     * Return the configured driver name for a disk, or null if the disk is not
     * declared (or its `driver` key is missing/non-string). Memoised — repeat
     * calls for the same disk skip the dotted-path config walk.
     */
    public function getDriverForDisk(string $disk): ?string
    {
        if (\array_key_exists($disk, $this->driver_cache)) {
            return $this->driver_cache[$disk];
        }

        $driver = $this->config->get("filesystems.disks.{$disk}.driver");

        return $this->driver_cache[$disk] = \is_string($driver) ? $driver : null;
    }
}
