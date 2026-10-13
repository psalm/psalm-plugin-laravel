<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\BladeBootstrapper;
use Psalm\LaravelPlugin\Blade\PsalmShadowRegistrar;
use Psalm\LaravelPlugin\Issues\BladeCompileFailure;

/**
 * End-to-end proof that a template the real Blade compiler throws on is reported as an issue on
 * the `.blade.php` file. `--no-progress` is on every run: it discards the plugin's warnings, which
 * is the case the issue exists for.
 */
#[CoversClass(BladeBootstrapper::class)]
#[CoversClass(PsalmShadowRegistrar::class)]
#[CoversClass(BladeCompileFailure::class)]
#[Group('subprocess')]
final class BladeCompileFailureTest extends TestCase
{
    use AnalysesFixtureApp;

    private const FIXTURE = __DIR__ . '/Fixtures/BladeCompileFailure';

    /**
     * @return list<array{type: string, file_name: string, line_from: int, severity: string, message: string}>
     */
    private function analyze(string $config): array
    {
        $issues = $this->fixtureIssues(
            self::FIXTURE,
            ['-c', $config, '--no-cache', '--threads=1', '--no-progress', '--output-format=json', '--show-info=true'],
        );

        return \array_map(static fn(array $issue): array => [
            'type' => (string) $issue['type'],
            'file_name' => (string) $issue['file_name'],
            'line_from' => (int) $issue['line_from'],
            'severity' => (string) $issue['severity'],
            'message' => (string) $issue['message'],
        ], $issues);
    }

    /**
     * @param list<array{type: string, file_name: string, line_from: int, severity: string, message: string}> $issues
     *
     * @return list<array{type: string, file_name: string, line_from: int, severity: string, message: string}>
     */
    private function ofType(array $issues, string $type): array
    {
        return \array_values(\array_filter($issues, static fn(array $issue): bool => $issue['type'] === $type));
    }

    #[Test]
    public function a_template_the_compiler_throws_on_is_reported_at_its_first_line(): void
    {
        $issues = $this->analyze('psalm.xml');
        $failures = $this->ofType($issues, 'BladeCompileFailure');

        $this->assertCount(1, $failures, \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
        $this->assertSame('resources/views/broken.blade.php', $failures[0]['file_name']);
        // The directive sits on line 2: line 1 is the anchor, not where the compiler stopped.
        $this->assertSame(1, $failures[0]['line_from']);
        $this->assertStringContainsString('LogicException: the explode directive needs a request', $failures[0]['message']);
        $this->assertSame('info', $failures[0]['severity'], 'experimental issues default to info');
    }

    #[Test]
    public function the_other_templates_are_still_analyzed(): void
    {
        $issues = $this->analyze('psalm.xml');

        $this->assertSame(
            [['resources/views/healthy.blade.php', 2]],
            \array_map(
                static fn(array $issue): array => [$issue['file_name'], $issue['line_from']],
                $this->ofType($issues, 'UndefinedFunction'),
            ),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function experimental_mode_reports_it_as_an_error(): void
    {
        $failures = $this->ofType($this->analyze('psalm-experimental.xml'), 'BladeCompileFailure');

        $this->assertCount(1, $failures);
        $this->assertSame('error', $failures[0]['severity']);
    }
}
