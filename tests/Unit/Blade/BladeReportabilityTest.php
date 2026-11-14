<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\PsalmShadowRegistrar;

/**
 * Two runs only the real Psalm can answer, each in a fixture of its own: whether a template is
 * reportable at the moment Psalm raises an issue in its shadow, and whether a run where nothing
 * compiled still reports why.
 */
#[CoversClass(PsalmShadowRegistrar::class)]
#[Group('subprocess')]
final class BladeReportabilityTest extends TestCase
{
    use AnalysesFixtureApp;

    private const ARGUMENTS = ['-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--output-format=json'];

    /**
     * Psalm reports `DuplicateFunction` while SCANNING the shadow. The remap re-emits it on the
     * template path, so the template has to be reportable by then, not only once analysis starts.
     */
    #[Test]
    public function a_diagnostic_psalm_raises_while_scanning_a_shadow_is_reported_on_the_template(): void
    {
        $issues = $this->fixtureIssues(__DIR__ . '/Fixtures/BladeScanDiagnostic', self::ARGUMENTS);

        $this->assertSame(
            [['DuplicateFunction', 4]],
            $this->onTemplate($issues, 'duplicate-function.blade.php'),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Every template fails to compile, so the boot activates nothing and returns false; the
     * template must still be reportable for its `BladeCompileError` to survive.
     */
    #[Test]
    public function a_run_where_every_template_fails_to_compile_still_reports_each_failure(): void
    {
        $issues = $this->fixtureIssues(__DIR__ . '/Fixtures/BladeCompileFailureOnly', self::ARGUMENTS);

        $this->assertSame(
            [['BladeCompileError', 1]],
            $this->onTemplate($issues, 'prose-directive.blade.php'),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param list<array<string, mixed>> $issues
     *
     * @return list<array{0: string, 1: int}> type and line of every issue reported on the template
     */
    private function onTemplate(array $issues, string $template): array
    {
        $found = [];

        foreach ($issues as $issue) {
            if (\str_ends_with((string) $issue['file_path'], $template)) {
                $found[] = [(string) $issue['type'], (int) $issue['line_from']];
            }
        }

        return $found;
    }
}
