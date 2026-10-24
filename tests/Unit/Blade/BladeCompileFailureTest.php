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

    /**
     * @param list<array{type: string, file_name: string, line_from: int, severity: string, message: string}> $issues
     *
     * @return array<string, array{type: string, file_name: string, line_from: int, severity: string, message: string}>
     */
    private function failuresByFile(array $issues): array
    {
        $failures = [];

        foreach ($this->ofType($issues, 'BladeCompileFailure') as $failure) {
            $failures[$failure['file_name']] = $failure;
        }

        \ksort($failures);

        return $failures;
    }

    #[Test]
    public function a_template_the_compiler_throws_on_is_reported_as_an_error_at_its_first_line(): void
    {
        $issues = $this->analyze('psalm.xml');
        $failure = $this->failuresByFile($issues)['resources/views/broken.blade.php'] ?? null;

        $this->assertNotNull($failure, \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
        // The directive sits on line 2, but the exception carries a line of the provider file, not
        // of the template: line 1 is the anchor.
        $this->assertSame(1, $failure['line_from']);
        $this->assertSame('Template not analyzed: the explode directive needs a request', $failure['message']);
        $this->assertSame('error', $failure['severity']);
    }

    #[Test]
    public function a_php_error_raised_by_the_compiler_names_the_directive_instead_of_the_psalm_cli_args(): void
    {
        $issues = $this->analyze('psalm.xml');
        $failure = $this->failuresByFile($issues)['resources/views/prose-directive.blade.php'] ?? null;

        // Prose `@component` reaches Laravel's compileComponent(null), whose str_contains(null)
        // deprecation Psalm's own error handler would turn into a message carrying the vendor path
        // and the full Psalm command line.
        $this->assertNotNull($failure, \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
        $this->assertSame(1, $failure['line_from']);
        $this->assertSame(
            'Template not analyzed: @component needs arguments; write @@component to print it as text',
            $failure['message'],
        );
        $this->assertSame('error', $failure['severity']);
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
    public function issue_handlers_suppress_it_per_template_path(): void
    {
        $issues = $this->analyze('psalm-suppressed.xml');

        $this->assertSame(
            ['resources/views/prose-directive.blade.php'],
            \array_keys($this->failuresByFile($issues)),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }
}
