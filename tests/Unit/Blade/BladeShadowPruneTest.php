<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\BladeBootstrapper;
use Psalm\LaravelPlugin\Blade\BladeIssueRemapHandler;
use Psalm\LaravelPlugin\Blade\ShadowManifest;
use Symfony\Component\Process\Process;

/**
 * End-to-end proof, over two real `vendor/bin/psalm` runs against one scratch copy of the fixture,
 * that a shadow and its manifest entry never outlive the template that produced them: the first run
 * compiles both templates, the second runs after both are deleted, the empty-project path that must
 * still reach `ShadowManifest::prune()`. Pruning one template among several is covered by
 * ShadowManifestTest. Neither run leaks a shadow path into the report.
 *
 * The scratch copy exists because the test deletes templates between runs, which would break every
 * other test sharing the committed fixture directory.
 */
#[CoversClass(BladeBootstrapper::class)]
#[CoversClass(BladeIssueRemapHandler::class)]
#[CoversClass(ShadowManifest::class)]
#[Group('subprocess')]
final class BladeShadowPruneTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/Fixtures/BladeIssueRemap';

    private const COPIED_FILES = [
        'app/Greeter.php',
        // bootstrap/app.php require_once's the `<x-chip>` class component (#1701) unconditionally too.
        'app/View/Components/Chip.php',
        'app/Providers/LivewireStubProvider.php',
        'app/Providers/PrefixedTagStubProvider.php',
        // bootstrap/app.php require_once's both of these unconditionally (#1505's
        // RouteHelperStubProvider); the scratch copy's boot fatals without them, whether or not any
        // copied template actually uses the @routes/@bogusroute directives they register.
        'app/Providers/RouteHelperStubProvider.php',
        'packages/route-helper/src/RouteGenerator.php',
        'bootstrap/app.php',
        'bootstrap/cache/.gitignore',
        'config/view.php',
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

        \file_put_contents($this->scratchDir . '/psalm.xml', <<<'XML'
            <?xml version="1.0"?>
            <psalm
                errorLevel="1"
                xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                xmlns="https://getpsalm.org/schema/config"
            >
                <projectFiles>
                    <directory name="app" />
                </projectFiles>
                <plugins>
                    <pluginClass class="Psalm\LaravelPlugin\Plugin">
                        <blade cacheDir=".cache/blade-shadows" />
                    </pluginClass>
                </plugins>
            </psalm>
            XML);
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

    /**
     * @return array{raw: string, issues: list<array{type: string, file_path: string, line_from: int}>}
     */
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

        $raw = $process->getOutput();
        $decoded = \json_decode($raw, true);
        $this->assertIsArray($decoded, "Psalm did not emit a JSON report.\n{$raw}\n{$process->getErrorOutput()}");

        $issues = [];

        foreach ($decoded as $issue) {
            $this->assertIsArray($issue);
            $issues[] = [
                'type' => (string) $issue['type'],
                'file_path' => (string) $issue['file_path'],
                'line_from' => (int) $issue['line_from'],
            ];
        }

        return ['raw' => $raw, 'issues' => $issues];
    }

    /**
     * @param list<array{type: string, file_path: string, line_from: int}> $issues
     *
     * @return list<int>
     */
    private function brokenTemplateLines(array $issues): array
    {
        $lines = [];

        foreach ($issues as $issue) {
            if ($issue['type'] === 'UndefinedPropertyFetch' && \str_ends_with($issue['file_path'], 'resources/views/broken.blade.php')) {
                $lines[] = $issue['line_from'];
            }
        }

        return $lines;
    }

    #[Test]
    public function a_shadow_and_its_manifest_entry_never_outlive_their_templates(): void
    {
        $first = $this->analyze();
        $this->assertSame([2], $this->brokenTemplateLines($first['issues']), $first['raw']);
        $this->assertCount(2, $this->shadowFiles(), 'sanity: two templates should compile to two shadows');
        $this->assertCount(2, $this->manifestEntries(), 'sanity: two templates should have two manifest entries');
        // A shadow path could leak into an issue's message, code snippet or taint trail as well as
        // `file_path`, so the whole raw report is searched.
        $this->assertStringNotContainsString('blade-shadows', $first['raw'], 'the first run leaked a shadow path');

        // Every template at once, so the run has none left: the path that must still reach prune().
        // broken.blade.php reported above, so its absence from the report is a real signal, not one
        // that holds whether or not the shadow gets pruned (suppressed.blade.php never reports).
        \unlink($this->scratchDir . '/resources/views/broken.blade.php');
        \unlink($this->scratchDir . '/resources/views/suppressed.blade.php');
        $second = $this->analyze();

        // Losing the class's only two callers legitimately reports its own UnusedClass issue, which
        // is noise here: the contract is about the shadow side.
        foreach ($second['issues'] as $issue) {
            $this->assertStringNotContainsString('blade.php', $issue['file_path'], $second['raw']);
        }

        $this->assertSame([], $this->shadowFiles(), 'an orphan shadow file survived on disk');
        $this->assertSame([], $this->manifestEntries(), 'an orphan manifest entry survived');
    }
}
