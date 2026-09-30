<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\ContractVar;
use Psalm\LaravelPlugin\Blade\ShadowManifest;
use Psalm\LaravelPlugin\Blade\ShadowResult;
use Psalm\LaravelPlugin\Blade\ViewDataContract;

#[CoversClass(ShadowManifest::class)]
final class ShadowManifestTest extends TestCase
{
    private string $shadowDir;

    private function emptyContract(): ViewDataContract
    {
        return new ViewDataContract([], false);
    }

    /** @return array{0: list<string>, 1: bool} */
    private function emptyReferences(): array
    {
        return [[], false];
    }

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
    public function prune_retires_superseded_metadata_without_unlinking_a_readers_generation(): void
    {
        $manifest = new ShadowManifest($this->shadowDir);
        $old = $manifest->store('/a.blade.php', 'old', new ShadowResult('<?php echo 1;', [1 => 1], null), $this->emptyContract(), null);
        $manifest->flush();
        $reader = new ShadowManifest($this->shadowDir);
        $reader->load();

        $new = $manifest->store('/a.blade.php', 'new', new ShadowResult('<?php echo 2;', [1 => 2], null), $this->emptyContract(), null);
        $manifest->prune(['/a.blade.php']);
        $manifest->flush();

        $reloaded = new ShadowManifest($this->shadowDir);
        $reloaded->load();
        $this->assertNull($reloaded->shadowEntry($old));
        $this->assertNotNull($reloaded->shadowEntry($new));
        $this->assertTrue($reader->isFresh('/a.blade.php', 'old'));
        $this->assertSame('<?php echo 1;', \file_get_contents($old));
    }

    #[Test]
    public function overlapping_writers_keep_each_generations_bytes_and_metadata_together(): void
    {
        $a = new ShadowManifest($this->shadowDir);
        $b = new ShadowManifest($this->shadowDir);
        $a->load();
        $b->load();
        $old = $a->store('/race.blade.php', 'old', new ShadowResult('<?php strlen([]);', [1 => 1], null), $this->emptyContract(), null);
        $new = $b->store('/race.blade.php', 'new', new ShadowResult("<?php strlen('ok');", [1 => 2], null), $this->emptyContract(), null);
        $b->flush();
        $a->flush();

        $reloaded = new ShadowManifest($this->shadowDir);
        $reloaded->load();
        $this->assertNotSame($old, $new);
        $this->assertSame('<?php strlen([]);', \file_get_contents($old));
        $this->assertSame("<?php strlen('ok');", \file_get_contents($new));
        $this->assertTrue($reloaded->isFresh('/race.blade.php', 'old'));
        $this->assertFalse($reloaded->isFresh('/race.blade.php', 'new'));
        $this->assertSame([1 => 1], $reloaded->shadowEntry($old)?->lineMap);
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

        $shadowPath = $manifest->store('/app/views/foo.blade.php', 'source', new ShadowResult('<?php echo 1; ?>', [1 => 1], null), $this->emptyContract(), $this->emptyReferences());

        $this->assertStringContainsString(\sha1('/app/views/foo.blade.php'), $shadowPath);
        $this->assertFileExists($shadowPath);
        $this->assertSame('<?php echo 1; ?>', \file_get_contents($shadowPath));
    }

    #[Test]
    public function round_trip_compile_store_reload_fresh_and_edited(): void
    {
        $manifest = new ShadowManifest($this->shadowDir);
        $manifest->load();

        $manifest->store('/app/views/foo.blade.php', 'source v1', new ShadowResult('<?php ?>', [1 => 1], null), $this->emptyContract(), $this->emptyReferences());
        $manifest->flush();

        $reloaded = new ShadowManifest($this->shadowDir);
        $reloaded->load();

        $this->assertTrue($reloaded->isFresh('/app/views/foo.blade.php', 'source v1'));
        $this->assertFalse($reloaded->isFresh('/app/views/foo.blade.php', 'source v2 — edited'));
        $this->assertFalse($reloaded->isFresh('/app/views/never-stored.blade.php', 'source v1'));
    }

    /**
     * The same environment string reused across a store and a reload must stay fresh: a manifest
     * that recomputed its own fingerprint differently on every construction would never hit.
     */
    #[Test]
    public function the_same_environment_string_stays_fresh_across_a_reload(): void
    {
        $manifest = new ShadowManifest($this->shadowDir, 'env-a');
        $manifest->load();

        $manifest->store('/app/views/foo.blade.php', 'source', new ShadowResult('<?php ?>', [1 => 1], null), $this->emptyContract(), $this->emptyReferences());
        $manifest->flush();

        $reloaded = new ShadowManifest($this->shadowDir, 'env-a');
        $reloaded->load();

        $this->assertTrue($reloaded->isFresh('/app/views/foo.blade.php', 'source'));
    }

    /**
     * #1517: two manifests over the same directory that differ only in `$environment` (the
     * compiler-environment hash) must not consider each other's entries fresh — an application
     * whose Blade wiring changed between runs must recompile even though the template source did
     * not.
     */
    #[Test]
    public function a_different_environment_is_never_fresh_against_an_entry_written_with_another(): void
    {
        $manifest = new ShadowManifest($this->shadowDir, 'env-a');
        $manifest->load();

        $manifest->store('/app/views/foo.blade.php', 'source', new ShadowResult('<?php ?>', [1 => 1], null), $this->emptyContract(), $this->emptyReferences());
        $manifest->flush();

        $reloaded = new ShadowManifest($this->shadowDir, 'env-b');
        $reloaded->load();

        $this->assertFalse($reloaded->isFresh('/app/views/foo.blade.php', 'source'));
    }

    #[Test]
    public function not_fresh_when_the_shadow_file_was_deleted_out_from_under_the_manifest(): void
    {
        $manifest = new ShadowManifest($this->shadowDir);
        $manifest->load();

        $shadowPath = $manifest->store('/app/views/foo.blade.php', 'source v1', new ShadowResult('<?php ?>', [1 => 1], null), $this->emptyContract(), $this->emptyReferences());
        $manifest->flush();

        \unlink($shadowPath);

        $reloaded = new ShadowManifest($this->shadowDir);
        $reloaded->load();

        $this->assertFalse($reloaded->isFresh('/app/views/foo.blade.php', 'source v1'));
    }

    #[Test]
    public function the_line_map_and_suppressions_survive_a_reload(): void
    {
        $manifest = new ShadowManifest($this->shadowDir);
        $manifest->load();

        $shadowPath = $manifest->store(
            '/app/views/foo.blade.php',
            'source',
            new ShadowResult('<?php ?>', [1 => 0, 2 => 4], null, [4 => ['UndefinedPropertyFetch']]),
            $this->emptyContract(),
            $this->emptyReferences(),
        );
        $manifest->flush();

        // The remap reads both off the manifest for any template fresh enough to skip recompiling,
        // so a shape that does not survive `var_export` + `include` silently loses suppressions.
        $reloaded = new ShadowManifest($this->shadowDir);
        $reloaded->load();

        $entry = $reloaded->shadowEntry($shadowPath);

        $this->assertNotNull($entry);
        $this->assertSame('/app/views/foo.blade.php', $entry->templatePath);
        $this->assertSame([1 => 0, 2 => 4], $entry->lineMap);
        $this->assertSame([4 => ['UndefinedPropertyFetch']], $entry->suppressions);
    }

    #[Test]
    public function an_entry_written_by_an_older_shape_is_dropped_rather_than_half_read(): void
    {
        \file_put_contents(
            $this->shadowDir . '/manifest.php',
            "<?php\n\nreturn ['/shadow.php' => ['/a.blade.php', [1 => 1], null, 'hash']];\n",
        );

        $manifest = new ShadowManifest($this->shadowDir);
        $manifest->load();

        $this->assertNull($manifest->shadowEntry('/shadow.php'));
        $this->assertFalse($manifest->isFresh('/a.blade.php', 'a'));
    }

    #[Test]
    public function the_template_contract_survives_a_reload(): void
    {
        $manifest = new ShadowManifest($this->shadowDir);
        $manifest->load();

        $shadowPath = $manifest->store(
            '/app/views/foo.blade.php',
            'source',
            new ShadowResult('<?php ?>', [], null),
            new ViewDataContract(
                [
                    'user' => new ContractVar('user', '\App\Models\User', 3, false),
                    'title' => new ContractVar('title', 'mixed', 1, true),
                ],
                true,
            ),
            $this->emptyReferences(),
        );
        $manifest->flush();

        // A template fresh enough to skip recompiling is never re-parsed, so a contract that does
        // not survive `var_export` + `include` silently stops validating that template's callers.
        $reloaded = new ShadowManifest($this->shadowDir);
        $reloaded->load();

        $contract = $reloaded->contractFor($shadowPath);

        $this->assertNotNull($contract);
        $this->assertTrue($contract->propsUnknown);
        $this->assertSame(['user', 'title'], \array_keys($contract->vars));
        $this->assertSame('\App\Models\User', $contract->vars['user']->typeString);
        $this->assertSame(3, $contract->vars['user']->declarationLine);
        $this->assertFalse($contract->vars['user']->optional);
        $this->assertTrue($contract->vars['title']->optional);
    }

    #[Test]
    public function an_entry_from_before_the_contract_slot_is_dropped(): void
    {
        // The five-slot shape this plugin wrote before contracts existed. Keeping it would leave
        // the template permanently fresh and permanently contract-less; dropping it costs one
        // recompile.
        \file_put_contents(
            $this->shadowDir . '/manifest.php',
            "<?php\n\nreturn ['/shadow.php' => ['/a.blade.php', [1 => 1], null, 'hash', []]];\n",
        );

        $manifest = new ShadowManifest($this->shadowDir);
        $manifest->load();

        $this->assertNull($manifest->shadowEntry('/shadow.php'));
        $this->assertNull($manifest->contractFor('/shadow.php'));
        $this->assertFalse($manifest->isFresh('/a.blade.php', 'a'));
    }

    #[Test]
    public function an_entry_from_before_the_references_slot_is_dropped(): void
    {
        // The six-slot shape this plugin wrote before UnusedView references existed. Keeping it
        // would leave the template permanently fresh with no references ever collected for it,
        // silently making it look unused forever.
        \file_put_contents(
            $this->shadowDir . '/manifest.php',
            "<?php\n\nreturn ['/shadow.php' => ['/a.blade.php', [1 => 1], null, 'hash', [], [[], false]]];\n",
        );

        $manifest = new ShadowManifest($this->shadowDir);
        $manifest->load();

        $this->assertNull($manifest->shadowEntry('/shadow.php'));
        $this->assertFalse($manifest->isFresh('/a.blade.php', 'a'));
    }

    #[Test]
    public function references_survive_a_reload(): void
    {
        $manifest = new ShadowManifest($this->shadowDir);
        $manifest->load();

        $shadowPath = $manifest->store(
            '/app/views/foo.blade.php',
            'source',
            new ShadowResult('<?php ?>', [], null),
            $this->emptyContract(),
            [['partial', 'layout'], false],
        );
        $manifest->flush();

        // A template fresh enough to skip recompiling is never re-parsed, so references that do not
        // survive `var_export` + `include` would resurrect it as UnusedView on the very next run.
        $reloaded = new ShadowManifest($this->shadowDir);
        $reloaded->load();

        $this->assertSame([['partial', 'layout'], false], $reloaded->referencesFor($shadowPath));
    }

    #[Test]
    public function an_entry_from_before_the_read_set_is_dropped(): void
    {
        // The seven-slot shape this plugin wrote before UnusedViewData widened the contract and added
        // the data-includes slot. Keeping it would leave the template permanently fresh with an empty
        // read set, silently reporting every key its callers pass.
        \file_put_contents(
            $this->shadowDir . '/manifest.php',
            "<?php\n\nreturn ['/shadow.php' => ['/a.blade.php', [1 => 1], null, 'hash', [], [[], false], [[], false]]];\n",
        );

        $manifest = new ShadowManifest($this->shadowDir);
        $manifest->load();

        $this->assertNull($manifest->shadowEntry('/shadow.php'));
        $this->assertFalse($manifest->isFresh('/a.blade.php', 'a'));
    }

    #[Test]
    public function the_read_set_and_the_data_includes_survive_a_reload(): void
    {
        $manifest = new ShadowManifest($this->shadowDir);
        $manifest->load();

        $shadowPath = $manifest->store(
            '/app/views/foo.blade.php',
            'source',
            new ShadowResult('<?php ?>', [], null),
            new ViewDataContract([], false, ['name', 'title'], false),
            $this->emptyReferences(),
            [['partial'], false],
        );
        $manifest->flush();

        // A template fresh enough to skip recompiling is never re-parsed, so a read set that does not
        // survive `var_export` + `include` reports every key its callers pass.
        $reloaded = new ShadowManifest($this->shadowDir);
        $reloaded->load();

        $contract = $reloaded->contractFor($shadowPath);

        $this->assertNotNull($contract);
        $this->assertSame(['name', 'title'], $contract->readVariables);
        $this->assertFalse($contract->readsUnknown);
        $this->assertSame([['partial'], false], $reloaded->dataIncludesFor($shadowPath));
    }

    /**
     * Flipping the rule on against a cache warmed while it was off must not leave every template
     * permanently "fresh with no data includes collected".
     */
    #[Test]
    public function an_entry_stored_without_data_includes_is_not_fresh_when_they_are_required(): void
    {
        $manifest = new ShadowManifest($this->shadowDir);
        $manifest->load();

        $manifest->store('/a.blade.php', 'a', new ShadowResult('<?php ?>', [], null), $this->emptyContract(), $this->emptyReferences());
        $manifest->flush();

        $reloaded = new ShadowManifest($this->shadowDir);
        $reloaded->load();

        $this->assertTrue($reloaded->isFresh('/a.blade.php', 'a'));
        $this->assertFalse($reloaded->isFresh('/a.blade.php', 'a', ShadowManifest::SLOT_DATA_INCLUDES));
    }

    #[Test]
    public function store_throws_and_leaves_a_pre_existing_shadow_untouched_when_the_directory_is_unwritable(): void
    {
        // Mode bits are a noop on Windows and a no-effect guard when running as root, so skip
        // there rather than false-pass (matches AddCommandTest::write_fails_and_preserves_error_when_tmp_cannot_be_written()).
        if (\DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX mode bits are required to force file_put_contents failure.');
        }

        if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            $this->markTestSkipped('Running as root bypasses directory write permissions.');
        }

        $manifest = new ShadowManifest($this->shadowDir);
        $manifest->load();

        $templatePath = '/app/views/foo.blade.php';
        $shadowPath = $manifest->shadowPathFor($templatePath, 'source');

        // The pre-existing shadow file is directly writable; only CREATING a fresh file in
        // the directory is blocked. A non-atomic write truncates the existing file in place
        // and succeeds silently; the temp-then-rename pattern must fail up front instead,
        // before touching the file store() is about to replace.
        \file_put_contents($shadowPath, 'original');
        \chmod($this->shadowDir, 0o555);

        $thrown = null;

        try {
            $manifest->store($templatePath, 'source', new ShadowResult('<?php ?>', [1 => 1], null), $this->emptyContract(), $this->emptyReferences());
        } catch (\RuntimeException $runtimeException) {
            $thrown = $runtimeException;
        } finally {
            \chmod($this->shadowDir, 0o777);
        }

        $this->assertInstanceOf(\RuntimeException::class, $thrown);
        $this->assertSame('original', \file_get_contents($shadowPath));
        $this->assertSame([], \glob($this->shadowDir . '/*.tmp.*'));
        // The bare path is not actionable on its own; the OS's own reason (e.g. permission
        // denied) must be appended so this doesn't read the same as every other failure mode.
        $this->assertStringContainsString(': ', $thrown->getMessage());
        $this->assertGreaterThan(\strlen("cannot write shadow file '{$shadowPath}'"), \strlen($thrown->getMessage()));
    }

    #[Test]
    public function store_leaves_no_temp_file_behind_after_a_successful_write(): void
    {
        $manifest = new ShadowManifest($this->shadowDir);
        $manifest->load();

        $manifest->store('/app/views/foo.blade.php', 'source', new ShadowResult('<?php echo 1; ?>', [1 => 1], null), $this->emptyContract(), $this->emptyReferences());

        $this->assertSame([], \glob($this->shadowDir . '/*.tmp.*'));
    }

    #[Test]
    public function prune_unlinks_orphan_shadows_and_drops_their_entries(): void
    {
        $manifest = new ShadowManifest($this->shadowDir);
        $manifest->load();

        $shadowA = $manifest->store('/a.blade.php', 'a', new ShadowResult('<?php ?>', [], null), $this->emptyContract(), $this->emptyReferences());
        $shadowB = $manifest->store('/b.blade.php', 'b', new ShadowResult('<?php ?>', [], null), $this->emptyContract(), $this->emptyReferences());
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
