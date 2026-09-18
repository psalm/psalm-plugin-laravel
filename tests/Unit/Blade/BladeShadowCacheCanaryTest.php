<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\BladeIssueRemapHandler;
use Psalm\LaravelPlugin\Blade\ShadowManifest;
use Symfony\Component\Process\Process;

/**
 * Forward guard against Psalm reviving its own cached-issue replay path (currently dead in CLI
 * mode: diff mode is hard-disabled, so a cached issue is never written against a shadow path in
 * the first place — replay would only ever reproduce the same template-path issue the remap
 * handler already produces). Runs the same fixture twice with Psalm's own file cache warm, so a
 * future Psalm that turns replay back on gets caught here instead of leaking a shadow path into a
 * report.
 */
#[CoversClass(BladeIssueRemapHandler::class)]
#[CoversClass(ShadowManifest::class)]
final class BladeShadowCacheCanaryTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/Fixtures/BladeIssueRemap';

    private const SHADOW_DIR = self::FIXTURE . '/.cache/blade-shadows-canary';

    private const PSALM_CACHE_DIR = self::FIXTURE . '/.cache/psalm-canary';

    private const ISSUE = 'UndefinedPropertyFetch';

    protected function setUp(): void
    {
        $this->deleteCacheDirs();
    }

    protected function tearDown(): void
    {
        $this->deleteCacheDirs();
    }

    private function deleteCacheDirs(): void
    {
        foreach ([self::SHADOW_DIR, self::PSALM_CACHE_DIR] as $dir) {
            $this->deleteDir($dir);
        }
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

    /**
     * @return list<array{type: string, file_path: string, line_from: int}>
     */
    private function analyze(): array
    {
        $psalmBinary = \dirname(__DIR__, 3) . '/vendor/bin/psalm';
        $this->assertFileExists($psalmBinary, 'Psalm binary not found — run composer install.');

        // No --no-cache: the whole point is a warm Psalm file cache on the second run, on
        // identical config both times — any config drift busts the cache and proves nothing.
        $process = new Process(
            [\PHP_BINARY, $psalmBinary, '-c', 'psalm-cached.xml', '--threads=1', '--no-progress', '--output-format=json'],
            self::FIXTURE,
        );
        $process->setTimeout(300);
        $process->run();

        $decoded = \json_decode($process->getOutput(), true);
        $this->assertIsArray($decoded, "Psalm did not emit a JSON report.\n{$process->getOutput()}\n{$process->getErrorOutput()}");

        $issues = [];

        foreach ($decoded as $issue) {
            $this->assertIsArray($issue);
            $issues[] = [
                'type' => (string) $issue['type'],
                'file_path' => (string) $issue['file_path'],
                'line_from' => (int) $issue['line_from'],
            ];
        }

        return $issues;
    }

    #[Test]
    public function a_warm_psalm_cache_never_leaks_the_shadow_path_and_still_reports_the_template_issue(): void
    {
        $first = $this->analyze();
        $second = $this->analyze();

        foreach (['first' => $first, 'second' => $second] as $label => $issues) {
            $lines = [];

            foreach ($issues as $issue) {
                $this->assertStringNotContainsString(
                    'blade-shadows-canary',
                    $issue['file_path'],
                    "{$label} run leaked a shadow path.\n" . \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
                );

                if ($issue['type'] === self::ISSUE && \str_ends_with($issue['file_path'], 'resources/views/broken.blade.php')) {
                    $lines[] = $issue['line_from'];
                }
            }

            $this->assertSame(
                [2],
                $lines,
                "{$label} run did not report the template issue.\n" . \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
            );
        }
    }
}
