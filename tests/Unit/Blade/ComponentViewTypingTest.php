<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\ComponentViewMap;
use Psalm\LaravelPlugin\Blade\PreludeBuilder;

/**
 * #1804: a class component's own view is typed from the literal array its `render()` passes. A real
 * `vendor/bin/psalm` run is the only way to pin it: the types come from Psalm analyzing the copied
 * array inside the shadow, with `$this` bound to the component class.
 */
#[CoversClass(ComponentViewMap::class)]
#[CoversClass(PreludeBuilder::class)]
#[Group('subprocess')]
final class ComponentViewTypingTest extends TestCase
{
    use AnalysesFixtureApp;

    private const FIXTURE = __DIR__ . '/Fixtures/ComponentViews';

    /** @var list<string> */
    private const ARGUMENTS = ['-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--output-format=json'];

    /**
     * @return list<array{type: string, line: int, message: string}>
     */
    private function issuesFor(string $template): array
    {
        $issues = [];

        foreach ($this->fixtureIssues(self::FIXTURE, self::ARGUMENTS) as $issue) {
            if (!\str_ends_with((string) $issue['file_path'], 'resources/views/' . $template)) {
                continue;
            }

            $issues[] = [
                'type' => (string) $issue['type'],
                'line' => (int) $issue['line_from'],
                'message' => (string) $issue['message'],
            ];
        }

        return $issues;
    }

    /**
     * @return list<string> the `Trace`/`UndefinedTrace` messages of one template
     */
    private function tracesFor(string $template): array
    {
        $traces = [];

        foreach ($this->issuesFor($template) as $issue) {
            if ($issue['type'] === 'Trace' || $issue['type'] === 'UndefinedTrace') {
                $traces[] = $issue['message'];
            }
        }

        \sort($traces);

        return $traces;
    }

    private function report(): string
    {
        return $this->analyzeFixture(self::FIXTURE, self::ARGUMENTS)['output'];
    }

    #[Test]
    public function render_keys_are_typed_in_class_scope_and_data_keys_stay_untyped(): void
    {
        // `$title` is a protected property and `$count` a protected method's return: both need the
        // closure bound to the class. `$type` (public property) and `$isActive` (public method) are
        // what `Component::data()` overrides on a `<x-alert>` render, so render()'s type never wins.
        $this->assertSame([
            '$count: int',
            '$isActive: mixed',
            "\$kind: 'alert'",
            '$title: string',
            '$type: mixed',
        ], $this->tracesFor('components/alert.blade.php'), $this->report());
    }

    #[Test]
    public function view_make_form_is_typed_and_a_raw_var_in_the_template_still_wins(): void
    {
        $this->assertSame([
            '$title: non-empty-string',
            '$width: 12',
        ], $this->tracesFor('components/card.blade.php'), $this->report());
    }

    #[Test]
    public function a_data_override_declines(): void
    {
        $this->assertSame(
            ['Attempt to trace undefined variable $label'],
            $this->tracesFor('components/badge.blade.php'),
            $this->report(),
        );
    }

    #[Test]
    public function a_view_no_component_renders_is_untouched(): void
    {
        $this->assertSame(
            ['Attempt to trace undefined variable $title'],
            $this->tracesFor('page.blade.php'),
            $this->report(),
        );
    }

    #[Test]
    public function render_data_carries_taint_into_the_template(): void
    {
        $tainted = \array_values(\array_filter(
            $this->issuesFor('components/alert.blade.php'),
            static fn(array $issue): bool => $issue['type'] === 'TaintedHtml',
        ));

        $this->assertCount(1, $tainted, $this->report());
        $this->assertSame(3, $tainted[0]['line'], $this->report());
    }

    /**
     * A class component's view runs in a `static` closure, so `$this` there is a runtime Error. This
     * repository installs no Livewire, which would bind it; a page no component renders keeps the
     * #1559 drop.
     */
    #[Test]
    public function this_reports_in_a_class_component_view_only(): void
    {
        $scopeIssues = static fn(array $issues): array => \array_values(\array_filter(
            $issues,
            static fn(array $issue): bool => $issue['type'] === 'InvalidScope',
        ));

        $this->assertSame(
            [['type' => 'InvalidScope', 'line' => 3, 'message' => 'Use of $this in non-class context']],
            $scopeIssues($this->issuesFor('components/card.blade.php')),
            $this->report(),
        );
        $this->assertSame([], $scopeIssues($this->issuesFor('page.blade.php')), $this->report());
    }

    #[Test]
    public function nothing_reports_on_the_copied_render_array(): void
    {
        // The copied array is render()'s own code, already analyzed in the class: anything Psalm
        // finds on it in the shadow is a duplicate with no template line, never parked on line 1.
        foreach (['components/alert.blade.php', 'components/card.blade.php'] as $template) {
            foreach ($this->issuesFor($template) as $issue) {
                $this->assertStringNotContainsString('(unmapped)', $issue['message'], $this->report());
            }
        }
    }
}
