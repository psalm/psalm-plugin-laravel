<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\BladeBootstrapper;
use Psalm\LaravelPlugin\Blade\ShadowManifest;
use Symfony\Component\Process\Process;

/**
 * End-to-end proof that a shadow and its manifest entry never outlive the template that produced
 * them, across two real `vendor/bin/psalm` runs. Each test gets a private scratch copy of the
 * fixture: it deletes templates between runs, which would break every other test sharing the
 * committed fixture directory.
 */
#[CoversClass(BladeBootstrapper::class)]
#[CoversClass(ShadowManifest::class)]
final class BladeShadowPruneTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/Fixtures/BladeIssueRemap';

    private const COPIED_FILES = [
        'app/Greeter.php',
        'app/Providers/LivewireStubProvider.php',
        // bootstrap/app.php require_once's both of these unconditionally (#1505's
        // RouteHelperStubProvider); the scratch copy's boot fatals without them, whether or not any
        // copied template actually uses the @routes/@bogusroute directives they register.
        'app/Providers/RouteHelperStubProvider.php',
        'packages/route-helper/src/RouteGenerator.php',
        'bootstrap/app.php',
        'bootstrap/cache/.gitignore',
        'config/view.php',
        'psalm.xml',
        'resources/views/broken.blade.php',
        'resources/views/suppressed.blade.php',
    ];

    private string $scratchDir;

    protected function setUp(): void
    {
        $this->scratchDir = \sys_get_temp_dir() . '/blade-shadow-prune-' . \bin2hex(\random_bytes(8));

        foreach (self::COPIED_FILES as $relative) {
            $target = $this->scratchDir . '/' . $relative;
            @\mkdir(\dirname($target), 0o777, true);
            \copy(self::FIXTURE . '/' . $relative, $target);
        }
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->scratchDir);
    }

    private function deleteDir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        foreach (\array_diff(\scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;

            \is_dir($path) ? $this->deleteDir($path) : \unlink($path);
        }

        \rmdir($dir);
    }

    private function shadowDir(): string
    {
        return $this->scratchDir . '/.cache/blade-shadows';
    }

    /** @return list<string> compiled shadow files, the manifest itself excluded */
    private function shadowFiles(): array
    {
        return \array_values(\array_filter(
            \glob($this->shadowDir() . '/*.php') ?: [],
            static fn(string $path): bool => \basename($path) !== 'manifest.php',
        ));
    }

    /** @return array<string, mixed> manifest entries, empty when the manifest file is absent */
    private function manifestEntries(): array
    {
        $manifest = $this->shadowDir() . '/manifest.php';

        if (!\is_file($manifest)) {
            return [];
        }

        /** @var array<string, mixed> $entries */
        $entries = include $manifest;

        return $entries;
    }

    /** @return list<array{type: string, file_path: string}> */
    private function analyze(): array
    {
        $psalmBinary = \dirname(__DIR__, 3) . '/vendor/bin/psalm';
        $this->assertFileExists($psalmBinary, 'Psalm binary not found — run composer install.');

        $process = new Process(
            [\PHP_BINARY, $psalmBinary, '-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--output-format=json'],
            $this->scratchDir,
        );
        $process->setTimeout(300);
        // Not mustRun(): the fixture reports issues on purpose.
        $process->run();

        $decoded = \json_decode($process->getOutput(), true);
        $this->assertIsArray($decoded, "Psalm did not emit a JSON report.\n{$process->getOutput()}\n{$process->getErrorOutput()}");

        $issues = [];

        foreach ($decoded as $issue) {
            $this->assertIsArray($issue);
            $issues[] = ['type' => (string) $issue['type'], 'file_path' => (string) $issue['file_path']];
        }

        return $issues;
    }

    #[Test]
    public function deleting_every_template_prunes_every_shadow_and_manifest_entry(): void
    {
        $this->analyze();
        $this->assertNotSame([], $this->shadowFiles(), 'sanity: the first run should have compiled shadows');

        \unlink($this->scratchDir . '/resources/views/broken.blade.php');
        \unlink($this->scratchDir . '/resources/views/suppressed.blade.php');

        $issues = $this->analyze();
        $dump = \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);

        // Losing the class's only two callers legitimately reports its own UnusedClass issue,
        // which is noise for this test: the pruning contract is about the shadow side, not about
        // whatever the now-templateless app class reports on its own.
        foreach ($issues as $issue) {
            $this->assertStringNotContainsString('blade.php', $issue['file_path'], $dump);
        }

        $this->assertSame([], $this->shadowFiles(), 'an orphan shadow file survived on disk');
        $this->assertSame([], $this->manifestEntries(), 'an orphan manifest entry survived');
    }

    #[Test]
    public function deleting_one_template_prunes_only_its_own_shadow(): void
    {
        $before = $this->analyze();

        // broken.blade.php, not suppressed.blade.php: the latter's inline @psalm-suppress
        // means it never reports an issue whether or not its shadow gets pruned, which would
        // make the "no issue after deletion" assertion below true regardless of the fix.
        $this->assertNotSame(
            [],
            \array_filter($before, static fn(array $issue): bool => \str_ends_with($issue['file_path'], 'broken.blade.php')),
            'sanity: the template being deleted should report an issue before deletion',
        );
        $this->assertCount(2, $this->shadowFiles(), 'sanity: two templates should compile to two shadows');

        \unlink($this->scratchDir . '/resources/views/broken.blade.php');

        $issues = $this->analyze();

        foreach ($issues as $issue) {
            $this->assertStringNotContainsString('broken.blade.php', $issue['file_path']);
        }

        $this->assertCount(1, $this->shadowFiles(), "the surviving template's shadow should remain alone");
        $this->assertCount(1, $this->manifestEntries(), "the deleted template's manifest entry should be gone");
    }
}
