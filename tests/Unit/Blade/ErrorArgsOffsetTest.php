<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Pins #1566 against a real Psalm run: `@error('field')` compiles to `$__errorArgs = ['field'];
 * $__bag = $errors->getBag($__errorArgs[1] ?? 'default');` (`CompilesErrors::compileError()`).
 * A single-argument directive's `$__errorArgs` is a literal one-element list, so
 * `ArrayFetchAnalyzer.php` reports `InvalidArrayOffset` for the `[1]` probe on every mention — compiler
 * bookkeeping the template author never wrote and cannot act on, dropped by `ShadowIssueRelocator`.
 *
 * The same fixture template also indexes an author's own literal array out of bounds
 * (`$arr = ['only']; echo $arr[1];`), which must keep reporting: the gate is exact-name on
 * `$__errorArgs`, not a wide family that would also swallow this.
 */
#[CoversNothing]
final class ErrorArgsOffsetTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/Fixtures/ErrorArgsOffset';

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
            static fn(array $issue): bool => $issue['file'] === 'error-offset.blade.php',
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
        $templatePath = \realpath(self::FIXTURE . '/resources/views/error-offset.blade.php');
        $this->assertIsString($templatePath, 'error-offset.blade.php does not exist on disk.');
        $this->assertStringContainsString(
            $templatePath,
            $manifest,
            'error-offset.blade.php was never compiled into a shadow, so the assertions below prove nothing.',
        );
        $this->assertNotSame([], $this->forTemplate($issues), $manifest);
    }

    #[Test]
    public function the_compiled_error_args_offset_is_dropped(): void
    {
        $issues = $this->analyze();
        $this->assertBladeAnalyzed($issues);

        $reported = $this->forTemplate($issues);
        $offsets = \array_filter($reported, static fn(array $issue): bool => $issue['type'] === 'InvalidArrayOffset');

        foreach ($offsets as $offset) {
            $this->assertStringNotContainsString('$__errorArgs', $offset['message'], \var_export($issues, true));
        }
    }

    /** Negative: the author's own out-of-bounds `$arr[1]` on line 8 must keep reporting. */
    #[Test]
    public function the_authors_own_invalid_array_offset_survives(): void
    {
        $issues = $this->analyze();
        $this->assertBladeAnalyzed($issues);

        $reported = $this->forTemplate($issues);
        $offsets = \array_values(\array_filter($reported, static fn(array $issue): bool => $issue['type'] === 'InvalidArrayOffset'));

        $this->assertNotSame([], $offsets, \var_export($issues, true));
        $this->assertStringContainsString('$arr', $offsets[0]['message'], \var_export($issues, true));
        $this->assertSame(8, $offsets[0]['line'], \var_export($issues, true));
    }
}
