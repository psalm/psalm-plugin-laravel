<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
    use AnalysesFixtureApp;

    private const FIXTURE = __DIR__ . '/Fixtures/ErrorArgsOffset';

    /** @var list<string> */
    private const ARGUMENTS = ['-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--output-format=json'];

    /**
     * @return list<array{type: string, file: string, line: int, message: string}>
     */
    private function analyze(): array
    {
        $issues = [];

        foreach ($this->fixtureIssues(self::FIXTURE, self::ARGUMENTS) as $issue) {
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
        $manifest = (string) \file_get_contents($this->fixtureShadows(self::FIXTURE, self::ARGUMENTS) . '/manifest.php');
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
