<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\MigrationCache;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumn;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumnDefault;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaTable;

#[CoversClass(MigrationCache::class)]
final class MigrationCacheTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = \sys_get_temp_dir() . '/psalm-migration-cache-test-' . \getmypid();
        \mkdir($this->cacheDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // Clean up all cache files
        $files = \glob($this->cacheDir . '/*');

        if ($files !== false) {
            foreach ($files as $file) {
                @\unlink($file);
            }
        }

        @\rmdir($this->cacheDir);
    }

    #[Test]
    public function cache_miss_calls_compute_and_returns_result(): void
    {
        $cache = new MigrationCache($this->cacheDir);
        $called = false;

        $tables = $cache->remember([], [], function () use (&$called): array {
            $called = true;
            $table = new SchemaTable();
            $table->setColumn(new SchemaColumn('id', 'int'));

            return ['users' => $table];
        });

        $this->assertTrue($called);
        $this->assertFalse($cache->wasCacheHit());
        $this->assertArrayHasKey('users', $tables);
        $this->assertArrayHasKey('id', $tables['users']->columns);
    }

    #[Test]
    public function cache_hit_skips_compute(): void
    {
        $cache = new MigrationCache($this->cacheDir);
        $callCount = 0;

        $compute = function () use (&$callCount): array {
            $callCount++;
            $table = new SchemaTable();
            $table->setColumn(new SchemaColumn('email', 'string'));

            return ['users' => $table];
        };

        // First call — cache miss
        $cache->remember([], [], $compute);
        $this->assertSame(1, $callCount);
        $this->assertFalse($cache->wasCacheHit());

        // Second call with same files — cache hit
        $tables = $cache->remember([], [], $compute);
        $this->assertSame(1, $callCount);
        $this->assertTrue($cache->wasCacheHit());
        $this->assertArrayHasKey('users', $tables);
        $this->assertSame('string', $tables['users']->columns['email']->type);
    }

    #[Test]
    public function cache_invalidates_when_migration_file_changes(): void
    {
        $migrationFile = $this->cacheDir . '/migration.php';
        \file_put_contents($migrationFile, '<?php // v1');
        // Ensure mtime is older for reliable invalidation
        \touch($migrationFile, \time() - 10);

        $cache = new MigrationCache($this->cacheDir);
        $callCount = 0;

        $compute = function () use (&$callCount): array {
            $callCount++;

            return ['users' => new SchemaTable()];
        };

        // First call — miss
        $cache->remember([$migrationFile], [], $compute);
        $this->assertSame(1, $callCount);

        // Same file, same mtime — hit
        $cache->remember([$migrationFile], [], $compute);
        $this->assertSame(1, $callCount);

        // Touch the file to change mtime — miss
        \touch($migrationFile, \time());
        \clearstatcache(true, $migrationFile);
        $cache->remember([$migrationFile], [], $compute);
        $this->assertSame(2, $callCount);
    }

    #[Test]
    public function cache_invalidates_when_file_added(): void
    {
        $file1 = $this->cacheDir . '/migration1.php';
        \file_put_contents($file1, '<?php // 1');

        $cache = new MigrationCache($this->cacheDir);
        $callCount = 0;

        $compute = function () use (&$callCount): array {
            $callCount++;

            return [];
        };

        // First call with one file
        $cache->remember([$file1], [], $compute);
        $this->assertSame(1, $callCount);

        // Add a second file — different fingerprint → miss
        $file2 = $this->cacheDir . '/migration2.php';
        \file_put_contents($file2, '<?php // 2');
        $cache->remember([$file1, $file2], [], $compute);
        $this->assertSame(2, $callCount);
    }

    #[Test]
    public function corrupted_cache_falls_back_to_compute(): void
    {
        $cache = new MigrationCache($this->cacheDir);

        // First call to populate cache
        $cache->remember([], [], fn(): array => ['users' => new SchemaTable()]);

        // Corrupt all cache files
        $files = \glob($this->cacheDir . '/psalm_laravel_migrations_*.cache');
        $this->assertNotFalse($files);

        foreach ($files as $file) {
            \file_put_contents($file, 'corrupted data');
        }

        // Should fall back to compute
        $callCount = 0;
        $cache->remember([], [], function () use (&$callCount): array {
            $callCount++;

            return [];
        });
        $this->assertSame(1, $callCount);
        $this->assertFalse($cache->wasCacheHit());
    }

    #[Test]
    public function old_cache_files_are_cleaned_up(): void
    {
        $cache = new MigrationCache($this->cacheDir);

        // First call creates a cache file
        $cache->remember([], [], fn(): array => []);

        $firstCacheFiles = \glob($this->cacheDir . '/psalm_laravel_migrations_*.cache');
        $this->assertNotFalse($firstCacheFiles);
        $this->assertCount(1, $firstCacheFiles);

        // Second call with different files — creates new cache, cleans old
        $file = $this->cacheDir . '/new_migration.php';
        \file_put_contents($file, '<?php');
        $cache->remember([$file], [], fn(): array => []);

        $secondCacheFiles = \glob($this->cacheDir . '/psalm_laravel_migrations_*.cache');
        $this->assertNotFalse($secondCacheFiles);
        $this->assertCount(1, $secondCacheFiles);

        // The file should be different from the first
        $this->assertNotEquals($firstCacheFiles[0], $secondCacheFiles[0]);
    }

    #[Test]
    public function preserves_full_schema_structure(): void
    {
        $cache = new MigrationCache($this->cacheDir);

        $tables = $cache->remember([], [], function (): array {
            $users = new SchemaTable();
            $users->setColumn(new SchemaColumn('id', 'int', unsigned: true));
            $users->setColumn(new SchemaColumn('email', 'string'));
            $users->setColumn(new SchemaColumn('is_active', 'bool', true));

            $posts = new SchemaTable();
            $posts->setColumn(new SchemaColumn('title', 'string'));

            return ['users' => $users, 'posts' => $posts];
        });

        // Read from cache
        $cached = $cache->remember([], [], fn(): array => $this->fail('Should not compute'));
        $this->assertTrue($cache->wasCacheHit());

        // Verify full structure is preserved
        $this->assertCount(2, $cached);
        $this->assertArrayHasKey('users', $cached);
        $this->assertArrayHasKey('posts', $cached);
        $this->assertCount(3, $cached['users']->columns);
        $this->assertSame('int', $cached['users']->columns['id']->type);
        $this->assertTrue($cached['users']->columns['id']->unsigned);
        $this->assertSame('string', $cached['users']->columns['email']->type);
        $this->assertTrue($cached['users']->columns['is_active']->nullable);
        $this->assertSame('string', $cached['posts']->columns['title']->type);
    }

    #[Test]
    public function preserves_column_defaults_and_enum_options(): void
    {
        $cache = new MigrationCache($this->cacheDir);

        $cache->remember([], [], function (): array {
            $table = new SchemaTable();
            $table->setColumn(new SchemaColumn('status', 'enum', false, ['draft', 'published'], default: SchemaColumnDefault::resolved('draft')));
            $table->setColumn(new SchemaColumn('score', 'float', false, default: SchemaColumnDefault::unresolvable()));
            $table->setColumn(new SchemaColumn('name', 'string'));

            return ['posts' => $table];
        });

        // Read from cache
        $cached = $cache->remember([], [], fn(): array => $this->fail('Should not compute'));
        $this->assertTrue($cache->wasCacheHit());

        $status = $cached['posts']->columns['status'];
        $this->assertSame(['draft', 'published'], $status->options);
        $this->assertNotNull($status->default);
        $this->assertTrue($status->default->resolvable);
        $this->assertSame('draft', $status->default->value);

        $score = $cached['posts']->columns['score'];
        $this->assertNotNull($score->default);
        $this->assertFalse($score->default->resolvable);

        $name = $cached['posts']->columns['name'];
        $this->assertNull($name->default);
    }

    #[Test]
    public function unwritable_cache_dir_falls_back_to_compute(): void
    {
        $cache = new MigrationCache('/nonexistent/path/that/cannot/exist');
        $callCount = 0;

        $tables = $cache->remember([], [], function () use (&$callCount): array {
            $callCount++;

            return ['users' => new SchemaTable()];
        });

        $this->assertSame(1, $callCount);
        $this->assertFalse($cache->wasCacheHit());
        $this->assertArrayHasKey('users', $tables);
    }

    #[Test]
    public function file_order_does_not_affect_fingerprint(): void
    {
        $fileA = $this->cacheDir . '/a_migration.php';
        $fileB = $this->cacheDir . '/b_migration.php';
        \file_put_contents($fileA, '<?php // a');
        \file_put_contents($fileB, '<?php // b');

        $cache = new MigrationCache($this->cacheDir);
        $callCount = 0;

        $compute = function () use (&$callCount): array {
            $callCount++;

            return [];
        };

        // First call with [A, B]
        $cache->remember([$fileA, $fileB], [], $compute);
        $this->assertSame(1, $callCount);

        // Second call with [B, A] — same fingerprint → cache hit
        $cache->remember([$fileB, $fileA], [], $compute);
        $this->assertSame(1, $callCount);
        $this->assertTrue($cache->wasCacheHit());
    }

    #[Test]
    public function sql_dump_files_included_in_fingerprint(): void
    {
        $cache = new MigrationCache($this->cacheDir);
        $callCount = 0;

        $compute = function () use (&$callCount): array {
            $callCount++;

            return [];
        };

        $sqlDump = $this->cacheDir . '/schema.sql';
        \file_put_contents($sqlDump, 'CREATE TABLE users (id INT);');

        // Without SQL dump
        $cache->remember([], [], $compute);
        $this->assertSame(1, $callCount);

        // With SQL dump — different fingerprint → miss
        $cache->remember([], [$sqlDump], $compute);
        $this->assertSame(2, $callCount);
    }
}
