<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\ShadowManifest;
use Psalm\LaravelPlugin\Blade\ShadowResult;

#[CoversClass(ShadowManifest::class)]
final class ShadowManifestTest extends TestCase
{
    private string $shadowDir;

    protected function setUp(): void
    {
        $this->shadowDir = \sys_get_temp_dir() . '/psalm-shadow-manifest-test-' . \getmypid();
        \mkdir($this->shadowDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $files = \glob($this->shadowDir . '/*');

        if ($files !== false) {
            foreach ($files as $file) {
                @\unlink($file);
            }
        }

        @\rmdir($this->shadowDir);
    }

    #[Test]
    public function load_tolerates_an_absent_manifest(): void
    {
        $manifest = new ShadowManifest($this->shadowDir);
        $manifest->load();

        $this->assertFalse($manifest->isFresh('/x.blade.php', 'contents'));
    }

    #[Test]
    public function load_tolerates_a_corrupt_manifest(): void
    {
        \file_put_contents($this->shadowDir . '/manifest.php', '<?php not valid php {{{');

        $manifest = new ShadowManifest($this->shadowDir);
        $manifest->load();

        $this->assertFalse($manifest->isFresh('/x.blade.php', 'contents'));
    }

    #[Test]
    public function store_writes_the_shadow_file_at_the_deterministic_path(): void
    {
        $manifest = new ShadowManifest($this->shadowDir);
        $manifest->load();

        $shadowPath = $manifest->store('/app/views/foo.blade.php', 'source', new ShadowResult('<?php echo 1; ?>', [1 => 1], null));

        $this->assertStringContainsString(\sha1('/app/views/foo.blade.php'), $shadowPath);
        $this->assertFileExists($shadowPath);
        $this->assertSame('<?php echo 1; ?>', \file_get_contents($shadowPath));
    }

    #[Test]
    public function round_trip_compile_store_reload_fresh_and_edited(): void
    {
        $manifest = new ShadowManifest($this->shadowDir);
        $manifest->load();

        $manifest->store('/app/views/foo.blade.php', 'source v1', new ShadowResult('<?php ?>', [1 => 1], null));
        $manifest->flush();

        $reloaded = new ShadowManifest($this->shadowDir);
        $reloaded->load();

        $this->assertTrue($reloaded->isFresh('/app/views/foo.blade.php', 'source v1'));
        $this->assertFalse($reloaded->isFresh('/app/views/foo.blade.php', 'source v2 — edited'));
        $this->assertFalse($reloaded->isFresh('/app/views/never-stored.blade.php', 'source v1'));
    }

    #[Test]
    public function not_fresh_when_the_shadow_file_was_deleted_out_from_under_the_manifest(): void
    {
        $manifest = new ShadowManifest($this->shadowDir);
        $manifest->load();

        $shadowPath = $manifest->store('/app/views/foo.blade.php', 'source v1', new ShadowResult('<?php ?>', [1 => 1], null));
        $manifest->flush();

        \unlink($shadowPath);

        $reloaded = new ShadowManifest($this->shadowDir);
        $reloaded->load();

        $this->assertFalse($reloaded->isFresh('/app/views/foo.blade.php', 'source v1'));
    }

    #[Test]
    public function prune_unlinks_orphan_shadows_and_drops_their_entries(): void
    {
        $manifest = new ShadowManifest($this->shadowDir);
        $manifest->load();

        $shadowA = $manifest->store('/a.blade.php', 'a', new ShadowResult('<?php ?>', [], null));
        $shadowB = $manifest->store('/b.blade.php', 'b', new ShadowResult('<?php ?>', [], null));
        $manifest->flush();

        $manifest->prune(['/a.blade.php']);
        $manifest->flush();

        $this->assertFileExists($shadowA);
        $this->assertFileDoesNotExist($shadowB);

        $reloaded = new ShadowManifest($this->shadowDir);
        $reloaded->load();

        $this->assertTrue($reloaded->isFresh('/a.blade.php', 'a'));
        $this->assertFalse($reloaded->isFresh('/b.blade.php', 'b'));
    }
}
