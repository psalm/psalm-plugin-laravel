<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\BladeIssueRemapHandler;
use Psalm\LaravelPlugin\Blade\JourneyRemapper;
use Psalm\LaravelPlugin\Blade\PsalmBridge;
use Psalm\LaravelPlugin\Blade\ShadowIssueRelocator;
use Psalm\LaravelPlugin\Blade\ShadowTarget;

/**
 * End-to-end proof that a taint finding inside a Blade template reads as a template finding: the
 * issue AND every step of its journey name the `.blade.php` file, never the compiled shadow.
 *
 * A real `vendor/bin/psalm --taint-analysis` run is the only way to pin it. The journey is built by
 * Psalm's taint graph from `DataFlowNode`s recorded during analysis, so no in-process construction
 * reproduces the node chain a template actually produces.
 */
#[CoversClass(BladeIssueRemapHandler::class)]
#[CoversClass(JourneyRemapper::class)]
#[CoversClass(PsalmBridge::class)]
#[CoversClass(ShadowIssueRelocator::class)]
#[CoversClass(ShadowTarget::class)]
final class BladeTaintRemapTest extends TestCase
{
    use AnalysesFixtureApp;

    private const FIXTURE = __DIR__ . '/Fixtures/BladeTaintRemap';

    /** @var list<string> */
    private const ARGUMENTS = [
        '-c',
        'psalm.xml',
        '--no-cache',
        '--threads=1',
        '--no-progress',
        '--taint-analysis',
        '--output-format=json',
    ];

    /**
     * The raw JSON Psalm printed plus its decoded issues. Raw matters: the shadow path has to be
     * absent from the WHOLE report, including the journey steps the decoded shape flattens away.
     *
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    private function report(): array
    {
        return [
            $this->analyzeFixture(self::FIXTURE, self::ARGUMENTS)['output'],
            $this->fixtureIssues(self::FIXTURE, self::ARGUMENTS),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function issuesFor(string $template): array
    {
        [, $issues] = $this->report();

        return \array_values(\array_filter(
            $issues,
            static fn(array $issue): bool => \str_ends_with((string) $issue['file_path'], $template),
        ));
    }

    #[Test]
    public function marker_shaped_literals_preserve_both_taint_findings(): void
    {
        $issues = $this->issuesFor('resources/views/marker.blade.php');
        $types = \array_column($issues, 'type');
        $this->assertContains('TaintedHtml', $types, $this->report()[0]);
        $this->assertContains('TaintedTextWithQuotes', $types, $this->report()[0]);
    }

    #[Test]
    public function an_unescaped_echo_of_request_input_is_tainted_on_the_template_line(): void
    {
        [$raw] = $this->report();
        $issues = $this->issuesFor('resources/views/tainted.blade.php');

        $tainted = \array_values(\array_filter(
            $issues,
            static fn(array $issue): bool => $issue['type'] === 'TaintedHtml',
        ));

        $this->assertCount(1, $tainted, $raw);
        $this->assertSame(2, $tainted[0]['line_from'], $raw);
    }

    #[Test]
    public function no_journey_step_names_the_compiled_shadow(): void
    {
        [$raw] = $this->report();

        // The whole report, not just the issue location: a journey step still pointing at the
        // shadow leaks a path that does not exist in the user's source tree.
        $this->assertStringNotContainsString('blade-shadows', $raw, $raw);
        $this->assertStringContainsString(
            'resources/views/tainted.blade.php',
            \str_replace('\/', '/', $raw),
            $raw,
        );
    }

    #[Test]
    public function a_journey_through_a_template_names_the_template_while_the_sink_stays_in_php(): void
    {
        // #1519: the sink is ordinary application code, not a shadow — only the journey hop that
        // passed through the template should move.
        [$raw] = $this->report();
        $issues = $this->issuesFor('app/Sink.php');

        $tainted = \array_values(\array_filter(
            $issues,
            static fn(array $issue): bool => $issue['type'] === 'TaintedHtml',
        ));

        $this->assertCount(1, $tainted, $raw);
        $this->assertSame('app/Sink.php', $tainted[0]['file_name'], $raw);

        $trace = $tainted[0]['taint_trace'];
        $this->assertIsArray($trace);

        $templateSteps = \array_values(\array_filter(
            $trace,
            static fn(array $step): bool => ($step['file_name'] ?? null) === 'resources/views/external.blade.php',
        ));

        $this->assertCount(1, $templateSteps, $raw);
        $this->assertSame(1, $templateSteps[0]['line_from'], $raw);
    }

    #[Test]
    public function an_escaped_echo_reports_no_taint(): void
    {
        $this->assertSame([], $this->issuesFor('resources/views/escaped.blade.php'), $this->report()[0]);
    }

    #[Test]
    public function an_echo_of_a_template_literal_reports_no_taint(): void
    {
        $this->assertSame([], $this->issuesFor('resources/views/literal.blade.php'), $this->report()[0]);
    }
}
