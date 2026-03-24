<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Schema;

use Composer\InstalledVersions;

/**
 * Fingerprint-based disk cache for parsed migration schema.
 *
 * Avoids re-parsing all migration files on every Psalm run when they haven't changed.
 * The cache key is a hash of sorted file paths + modification times + plugin version,
 * so any file change or plugin upgrade automatically invalidates the cache.
 *
 * Inspired by Larastan's MigrationCache (src/Properties/MigrationCache.php).
 *
 * @internal
 */
final class MigrationCache
{
    private const CACHE_PREFIX = 'psalm_laravel_migrations_';

    private const CACHE_EXTENSION = '.cache';

    private bool $cacheHit = false;

    private bool $cacheWritten = false;

    /** Diagnostic message when cache read fails (corrupt data, permission errors) */
    private ?string $readFailureReason = null;

    /** Diagnostic message when cache write fails (disk full, permission errors) */
    private ?string $writeFailureReason = null;

    /** @psalm-mutation-free */
    public function __construct(
        private readonly string $cacheDirectory,
    ) {}

    /**
     * Return cached schema tables if the fingerprint matches, otherwise
     * execute the callback, cache the result, and return it.
     *
     * Any cache failure (read, write, corrupt data) degrades gracefully
     * to a full parse — caching never blocks analysis.
     *
     * @param list<string> $migrationFiles PHP migration file paths
     * @param list<string> $sqlDumpFiles SQL schema dump file paths
     * @param callable(): array<string, SchemaTable> $compute
     * @return array<string, SchemaTable>
     */
    public function remember(array $migrationFiles, array $sqlDumpFiles, callable $compute): array
    {
        $fingerprint = $this->generateFingerprint($migrationFiles, $sqlDumpFiles);
        $cachePath = $this->getCachePath($fingerprint);

        $cached = $this->readFromCache($cachePath);

        if ($cached !== null) {
            $this->cacheHit = true;

            return $cached;
        }

        $this->cacheHit = false;

        $tables = $compute();

        $this->writeToCache($cachePath, $tables);
        $this->cleanupOldCacheFiles($fingerprint);

        return $tables;
    }

    public function wasCacheHit(): bool
    {
        return $this->cacheHit;
    }

    public function wasCacheWritten(): bool
    {
        return $this->cacheWritten;
    }

    /**
     * Generate a fingerprint from file metadata and plugin version.
     *
     * Uses sorted file paths + modification times so file discovery order
     * does not affect the fingerprint. The plugin version is included so
     * a plugin upgrade (which may change schema parsing logic) automatically
     * invalidates the cache.
     *
     * @param list<string> $migrationFiles
     * @param list<string> $sqlDumpFiles
     */
    private function generateFingerprint(array $migrationFiles, array $sqlDumpFiles): string
    {
        $entries = [];

        foreach ($migrationFiles as $file) {
            $mtime = @\filemtime($file);
            $entries[] = 'M:' . $file . ':' . ($mtime !== false ? $mtime : '0');
        }

        foreach ($sqlDumpFiles as $file) {
            $mtime = @\filemtime($file);
            $entries[] = 'S:' . $file . ':' . ($mtime !== false ? $mtime : '0');
        }

        \sort($entries);

        $data = \implode('|', $entries);

        // Include plugin version so schema parsing changes in new releases
        // automatically invalidate the cache
        $pluginVersion = InstalledVersions::getVersion('psalm/plugin-laravel') ?? 'unknown';
        $data .= '|V:' . $pluginVersion;

        return \hash('xxh128', $data);
    }

    /** @psalm-mutation-free */
    private function getCachePath(string $fingerprint): string
    {
        return $this->cacheDirectory
            . \DIRECTORY_SEPARATOR
            . self::CACHE_PREFIX
            . $fingerprint
            . self::CACHE_EXTENSION;
    }

    /**
     * @return array<string, SchemaTable>|null
     */
    private function readFromCache(string $cachePath): ?array
    {
        if (!\is_file($cachePath)) {
            return null;
        }

        $contents = @\file_get_contents($cachePath);

        if ($contents === false) {
            $this->readFailureReason = "cannot read cache file '{$cachePath}' — check file permissions";

            return null;
        }

        // Restrict allowed classes to prevent deserialization of unexpected types,
        // and suppress warnings on corrupted/incompatible data
        $data = @\unserialize($contents, [
            'allowed_classes' => [SchemaTable::class, SchemaColumn::class, SchemaColumnDefault::class],
        ]);

        if (!\is_array($data)) {
            $this->readFailureReason = "corrupt or incompatible cache file '{$cachePath}' — delete it or run with --clear-cache";

            return null;
        }

        /** @var array<string, SchemaTable> $data */
        return $data;
    }

    /**
     * Returns a diagnostic message if the cache read failed for a reason
     * other than a simple miss (file not found). Null means cache miss or hit.
     *
     * @psalm-mutation-free
     */
    public function getReadFailureReason(): ?string
    {
        return $this->readFailureReason;
    }

    /**
     * Returns a diagnostic message if the cache write failed.
     * Null means write succeeded or was not attempted (cache hit).
     *
     * @psalm-mutation-free
     */
    public function getWriteFailureReason(): ?string
    {
        return $this->writeFailureReason;
    }

    /**
     * Write cache data atomically using a temp file + rename.
     *
     * rename() is atomic on POSIX systems, so concurrent Psalm runs
     * cannot observe a partially written cache file.
     *
     * @param array<string, SchemaTable> $tables
     */
    private function writeToCache(string $cachePath, array $tables): void
    {
        $pid = \getmypid();
        $tmpPath = $cachePath . '.tmp.' . ($pid !== false ? $pid : 'unknown');

        $written = @\file_put_contents($tmpPath, \serialize($tables));

        if ($written === false) {
            $error = \error_get_last();
            $this->writeFailureReason = $error !== null
                ? "cannot write temp cache file '{$tmpPath}': {$error['message']}"
                : "cannot write temp cache file '{$tmpPath}'";

            return;
        }

        // Atomic replace — if rename fails, clean up the temp file
        if (@\rename($tmpPath, $cachePath)) {
            $this->cacheWritten = true;
        } else {
            $error = \error_get_last();
            $this->writeFailureReason = $error !== null
                ? "cannot rename cache file to '{$cachePath}': {$error['message']}"
                : "cannot rename cache file to '{$cachePath}'";
            @\unlink($tmpPath);
        }
    }

    /**
     * Remove stale cache files that don't match the current fingerprint.
     *
     * Runs after every cache miss to prevent unbounded accumulation
     * of old cache files as migrations change over time.
     */
    private function cleanupOldCacheFiles(string $currentFingerprint): void
    {
        $pattern = $this->cacheDirectory
            . \DIRECTORY_SEPARATOR
            . self::CACHE_PREFIX
            . '*'
            . self::CACHE_EXTENSION;

        $currentCachePath = $this->getCachePath($currentFingerprint);

        $files = \glob($pattern);

        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if ($file !== $currentCachePath) {
                @\unlink($file);
            }
        }
    }
}
