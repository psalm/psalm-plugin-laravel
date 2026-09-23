<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Pins #1545: a magic property fetch on a receiver sealed by `sealAllProperties="true"` reaches
 * Psalm through two emission sites for the SAME fetch. `AtomicPropertyFetchAnalyzer`'s direct
 * property-fetch handling reports `UndefinedMagicPropertyFetch` at the real fetch node, which maps
 * to the correct template line. `ExistingAtomicMethodCallAnalyzer`'s `__get` handling re-checks the
 * same seal on a synthesized `__get()` call and reports `UndefinedThisPropertyFetch` again, whose
 * location falls outside the shadow's line map and lands on line 1 with the `(unmapped)` suffix. A
 * shadow file declares no class, so an unmapped `UndefinedThisPropertyFetch` there can never be a
 * genuine `$this` fetch — it is always this duplicate.
 *
 * A real `vendor/bin/psalm` run is the only way to pin it: both emission sites only fire once the
 * fixture's `sealAllProperties="true"` config and a real magic-property fetch are analyzed together.
 */
#[CoversNothing]
final class UndefinedThisPropertyDuplicateTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/Fixtures/UndefinedThisProperty';

    private const SHADOW_DIR = self::FIXTURE . '/.cache/blade-shadows';

    protected function setUp(): void
    {
        $this->deleteShadowDir();
    }

    protected function tearDown(): void
    {
        $this->deleteShadowDir();
    }

    private function deleteShadowDir(): void
    {
        if (!\is_dir(self::SHADOW_DIR)) {
            return;
        }

        foreach (\array_diff(\scandir(self::SHADOW_DIR) ?: [], ['.', '..']) as $entry) {
            \unlink(self::SHADOW_DIR . '/' . $entry);
        }

        \rmdir(self::SHADOW_DIR);
    }

    /**
     * @return list<array{type: string, file: string, line: int, message: string}>
     */
    private function analyze(): array
    {
        $psalmBinary = \dirname(__DIR__, 3) . '/vendor/bin/psalm';
        $this->assertFileExists($psalmBinary, 'Psalm binary not found — run composer install.');

        $process = new Process(
            [\PHP_BINARY, $psalmBinary, '-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--output-format=json'],
            self::FIXTURE,
        );
        $process->setTimeout(300);
        // Not mustRun(): the fixture reports issues on purpose.
        $process->run();

        $decoded = \json_decode($process->getOutput(), true);
        $this->assertIsArray($decoded, "Psalm did not emit a JSON report.\n{$process->getOutput()}\n{$process->getErrorOutput()}");

        $issues = [];

        foreach ($decoded as $issue) {
            $this->assertIsArray($issue);
            $issues[] = [
                'type' => (string) $issue['type'],
                'file' => \basename((string) $issue['file_name']),
                'line' => (int) $issue['line_from'],
                'message' => (string) $issue['message'],
            ];
        }

        return $issues;
    }

    /**
     * @param list<array{type: string, file: string, line: int, message: string}> $issues
     *
     * @return list<array{type: string, file: string, line: int, message: string}>
     */
    private function forTemplate(array $issues): array
    {
        return \array_values(\array_filter(
            $issues,
            static fn(array $issue): bool => $issue['file'] === 'missing-prop.blade.php',
        ));
    }

    /**
     * A silent assertion proves nothing if the template never compiled. The manifest records every
     * template that made it through `compileAll()` this run, keyed by its real path.
     *
     * @param list<array{type: string, file: string, line: int, message: string}> $issues
     */
    private function assertBladeAnalyzed(array $issues): void
    {
        $manifest = (string) \file_get_contents(self::SHADOW_DIR . '/manifest.php');
        $templatePath = \realpath(self::FIXTURE . '/resources/views/missing-prop.blade.php');
        $this->assertIsString($templatePath, 'missing-prop.blade.php does not exist on disk.');
        $this->assertStringContainsString(
            $templatePath,
            $manifest,
            'missing-prop.blade.php was never compiled into a shadow, so the assertions below prove nothing.',
        );
        $this->assertNotSame([], $this->forTemplate($issues), $manifest);
    }

    #[Test]
    public function the_mapped_twin_survives_and_the_unmapped_duplicate_is_dropped(): void
    {
        $issues = $this->analyze();
        $this->assertBladeAnalyzed($issues);

        $reported = $this->forTemplate($issues);
        $types = \array_column($reported, 'type');

        $this->assertContains('UndefinedMagicPropertyFetch', $types, \var_export($issues, true));
        $this->assertNotContains('UndefinedThisPropertyFetch', $types, \var_export($issues, true));

        foreach ($reported as $issue) {
            if ($issue['type'] !== 'UndefinedMagicPropertyFetch') {
                continue;
            }

            // The real fetch node's own line — 5, where `{{ $magic->missing }}` sits — proving the
            // twin that DID map is the one that survives, not merely that some issue remains.
            $this->assertSame(5, $issue['line'], \var_export($issues, true));
        }
    }
}
