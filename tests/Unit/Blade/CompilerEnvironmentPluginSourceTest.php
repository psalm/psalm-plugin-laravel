<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\CompilerEnvironment;
use Psalm\LaravelPlugin\Blade\ShadowManifest;
use Psalm\LaravelPlugin\Blade\ShadowResult;
use Psalm\LaravelPlugin\Blade\ViewDataContract;

/**
 * #1869: a dev install reports one Composer version for every commit, so the plugin's own source
 * must reach the shadow fingerprint, or a pipeline edit keeps serving shadows from an older plugin.
 */
#[CoversClass(CompilerEnvironment::class)]
#[CoversClass(ShadowManifest::class)]
final class CompilerEnvironmentPluginSourceTest extends TestCase
{
    private const TREE = [
        'src/Blade/PreludeBuilder.php' => "<?php\n// prelude v1\n",
        'src/Plugin.php' => "<?php\n// plugin\n",
        'stubs/common/View/Factory.phpstub' => "<?php\n// factory\n",
    ];

    private string $root;

    protected function setUp(): void
    {
        $root = \tempnam(\sys_get_temp_dir(), 'plugin-source-');
        $this->assertIsString($root);
        \unlink($root);
        \mkdir($root, 0o777, true);
        $this->root = $root;
    }

    protected function tearDown(): void
    {
        // Restore permissions a test revoked, or the cleanup itself cannot descend.
        \exec('chmod -R u+rwx ' . \escapeshellarg($this->root));
        (new Filesystem())->deleteDirectory($this->root);
    }

    #[Test]
    public function a_same_length_source_edit_with_its_mtime_restored_stales_the_manifest(): void
    {
        $plugin = $this->tree('plugin', self::TREE);
        $shadowDir = $this->root . '/shadows';
        \mkdir($shadowDir);

        [$before, $trustedBefore] = $this->describe($plugin);
        $manifest = new ShadowManifest($shadowDir, $before);
        $manifest->load();

        $storedPath = $manifest->store('/app/views/a.blade.php', 'source', new ShadowResult('<?php ?>', [1 => 1], null), new ViewDataContract([], false));
        $manifest->flush();

        $unchanged = new ShadowManifest($shadowDir, $this->describe($plugin)[0]);
        $unchanged->load();
        $this->assertTrue($unchanged->isFresh('/app/views/a.blade.php', 'source'), 'unchanged plugin source and template must hit the cache');

        $prelude = $plugin . '/src/Blade/PreludeBuilder.php';
        $mtime = \filemtime($prelude);
        $this->assertIsInt($mtime);
        \file_put_contents($prelude, "<?php\n// prelude v2\n");
        \touch($prelude, $mtime);
        \clearstatcache();

        [$after, $trustedAfter] = $this->describe($plugin);
        $edited = new ShadowManifest($shadowDir, $after);
        $edited->load();

        $this->assertTrue($trustedBefore);
        $this->assertTrue($trustedAfter);
        $this->assertFalse($edited->isFresh('/app/views/a.blade.php', 'source'));
        $this->assertNotSame($storedPath, $edited->shadowPathFor('/app/views/a.blade.php', 'source'));
    }

    #[Test]
    public function adding_deleting_or_renaming_a_source_file_changes_the_hash(): void
    {
        $plugin = $this->tree('plugin', self::TREE);
        [$base] = $this->describe($plugin);

        \file_put_contents($plugin . '/src/Added.php', "<?php\n");
        [$added] = $this->describe($plugin);
        \unlink($plugin . '/src/Added.php');
        [$removed] = $this->describe($plugin);

        \file_put_contents($plugin . '/stubs/Added.phpstub', "<?php\n");
        [$addedStub] = $this->describe($plugin);
        \unlink($plugin . '/stubs/Added.phpstub');

        \unlink($plugin . '/src/Plugin.php');
        [$deleted] = $this->describe($plugin);
        \file_put_contents($plugin . '/src/Plugin.php', "<?php\n// plugin\n");

        \rename($plugin . '/stubs/common/View/Factory.phpstub', $plugin . '/stubs/common/View/Renamed.phpstub');
        [$renamed] = $this->describe($plugin);
        \rename($plugin . '/stubs/common/View/Renamed.phpstub', $plugin . '/stubs/common/View/Factory.phpstub');

        $stub = $plugin . '/stubs/common/View/Factory.phpstub';
        \file_put_contents($stub, "<?php\n// factorY\n");
        [$editedStub] = $this->describe($plugin);
        \file_put_contents($stub, "<?php\n// factory\n");

        \file_put_contents($plugin . '/src/README.md', 'not source');
        [$ignored] = $this->describe($plugin);

        $this->assertNotSame($base, $added);
        $this->assertSame($base, $removed);
        $this->assertNotSame($base, $addedStub);
        $this->assertNotSame($base, $deleted);
        $this->assertNotSame($base, $renamed);
        $this->assertNotSame($base, $editedStub);
        $this->assertSame($base, $ignored, 'only .php under src/ and .phpstub under stubs/ count');
    }

    #[Test]
    public function identical_trees_hash_equal_regardless_of_root_creation_order_and_mtime(): void
    {
        $first = $this->tree('first', self::TREE);
        $second = $this->tree('second', \array_reverse(self::TREE, true));

        foreach (\array_keys(self::TREE) as $offset => $relative) {
            \touch($second . '/' . $relative, 1_000_000_000 + $offset);
        }

        \clearstatcache();

        $this->assertSame($this->describe($first), $this->describe($second));
    }

    /** A naive path . content concatenation reads one file holding the next path as two files. */
    #[Test]
    public function a_path_and_content_boundary_shift_does_not_collide(): void
    {
        $split = $this->tree('split', ['src/a.php' => '<?php 1', 'src/b.php' => '<?php 2']);
        $merged = $this->tree('merged', ['src/a.php' => '<?php 1src/b.php<?php 2']);
        \mkdir($split . '/stubs');
        \mkdir($merged . '/stubs');

        $this->assertNotSame($this->describe($split)[0], $this->describe($merged)[0]);
    }

    #[Test]
    public function a_missing_source_directory_is_untrusted(): void
    {
        $noStubs = $this->tree('no-stubs', ['src/Plugin.php' => '<?php']);
        $noSrc = $this->tree('no-src', ['stubs/a.phpstub' => '<?php']);

        $this->assertFalse($this->describe($noStubs)[1]);
        $this->assertFalse($this->describe($noSrc)[1]);
        $this->assertFalse($this->describe($this->root . '/absent')[1]);
    }

    #[Test]
    public function an_unreadable_file_or_directory_is_untrusted(): void
    {
        $file = $this->tree('file', self::TREE);
        $directory = $this->tree('dir', self::TREE);
        \chmod($file . '/src/Plugin.php', 0o000);
        \chmod($directory . '/stubs/common', 0o000);

        if (\is_readable($file . '/src/Plugin.php')) {
            $this->markTestSkipped('permissions are not enforced for this user');
        }

        $this->assertFalse($this->describe($file)[1]);
        $this->assertFalse($this->describe($directory)[1]);
    }

    #[Test]
    public function an_untrusted_plugin_source_names_its_root_in_the_reasons(): void
    {
        [, $trusted, $reasons] = $this->describe($this->root . '/absent');

        $this->assertFalse($trusted);
        $this->assertSame(["plugin source (src/, stubs/ under {$this->root}/absent) missing or unreadable"], $reasons);
    }

    /** `file_get_contents()` returns the bytes read so far, not `false`, when a read fails midway. */
    #[Test]
    public function a_read_that_fails_midway_is_untrusted(): void
    {
        $plugin = $this->tree('plugin', self::TREE);
        TruncatingStreamWrapper::register();

        try {
            TruncatingStreamWrapper::$failingSuffix = '';
            $intact = $this->describe(TruncatingStreamWrapper::SCHEME . '://' . $plugin);
            TruncatingStreamWrapper::$failingSuffix = '/src/Plugin.php';
            $truncated = $this->describe(TruncatingStreamWrapper::SCHEME . '://' . $plugin);
        } finally {
            TruncatingStreamWrapper::$failingSuffix = '';
            TruncatingStreamWrapper::unregister();
        }

        $this->assertSame($this->describe($plugin), $intact, 'the wrapper alone must not change the hash');
        $this->assertFalse($truncated[1]);
    }

    /** On POSIX a backslash is an ordinary filename byte, not a separator to normalize. */
    #[Test]
    public function a_backslash_in_a_posix_filename_does_not_merge_with_a_subdirectory(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('a backslash is a separator on Windows');
        }

        $both = $this->tree('both', ['src/a\\b.php' => '<?php 1', 'src/a/b.php' => '<?php 2', 'stubs/s.phpstub' => '']);
        $slashOnly = $this->tree('slash', ['src/a/b.php' => '<?php 2', 'stubs/s.phpstub' => '']);
        $backslashOnly = $this->tree('backslash', ['src/a\\b.php' => '<?php 1', 'stubs/s.phpstub' => '']);

        $this->assertNotSame($this->describe($both)[0], $this->describe($slashOnly)[0]);
        $this->assertNotSame($this->describe($both)[0], $this->describe($backslashOnly)[0]);
    }

    /**
     * Pins the digest to byte-sorted relative paths. Depth-first traversal visits `src/a/z.php`
     * before `src/a.php` even under a sorted readdir (`a` < `a.php`), while the byte order is
     * `src/a-b.php` < `src/a.php` < `src/a/z.php`, so dropping the sort changes the digest.
     */
    #[Test]
    public function the_digest_follows_byte_sorted_relative_paths(): void
    {
        $files = ['src/a/z.php' => 'z', 'src/a.php' => 'a', 'src/a-b.php' => 'ab', 'stubs/s.phpstub' => 's'];
        $plugin = $this->tree('sorted', $files);

        $context = \hash_init('xxh128');

        foreach (['src/a-b.php', 'src/a.php', 'src/a/z.php', 'stubs/s.phpstub'] as $relative) {
            \hash_update($context, \pack('J', \strlen($relative)) . $relative . \pack('J', \strlen($files[$relative])) . $files[$relative]);
        }

        $hash = new \ReflectionMethod(CompilerEnvironment::class, 'hashPluginSource');

        $this->assertSame(\hash_final($context), $hash->invoke(null, $plugin));
    }

    #[Test]
    public function repeated_calls_on_the_plugins_own_source_are_trusted_and_stable(): void
    {
        $first = CompilerEnvironment::describe($this->compiler());
        CompilerEnvironment::reset();
        $second = CompilerEnvironment::describe($this->compiler());

        $this->assertTrue($first[1]);
        $this->assertSame($first, $second);
        $this->assertSame($first, $this->describe(\dirname(__DIR__, 3)), 'the default root is the plugin checkout');
    }

    /** @return array{0: string, 1: bool, 2: list<string>} */
    private function describe(string $pluginRoot): array
    {
        return CompilerEnvironment::describe($this->compiler(), $pluginRoot);
    }

    private function compiler(): BladeCompiler
    {
        return new BladeCompiler(new Filesystem(), $this->root . '/compiled');
    }

    /** @param array<string, string> $files relative path => contents, written in iteration order */
    private function tree(string $name, array $files): string
    {
        $root = $this->root . '/' . $name;

        foreach ($files as $relative => $contents) {
            $path = $root . '/' . $relative;
            @\mkdir(\dirname($path), 0o777, true);
            \file_put_contents($path, $contents);
        }

        return $root;
    }
}
