<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end proof that a value a controller hands to `view()` reaches the template's taint graph
 * (#2031). Only a real `vendor/bin/psalm` run can pin it: the source half writes while a PHP file is
 * analysed and the sink half reads while a compiled shadow is, in a different worker, and the two meet
 * only in the parent's merged graph.
 */
#[Group('subprocess')]
final class BladeViewDataTaintTest extends TestCase
{
    use AnalysesFixtureApp;

    private const FIXTURE = __DIR__ . '/Fixtures/BladeViewDataTaint';

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
     * Taint issues only, keyed by file name.
     *
     * @return list<array<string, mixed>>
     */
    private function taintIssuesIn(string $file): array
    {
        return \array_values(\array_filter(
            $this->fixtureIssues(self::FIXTURE, self::ARGUMENTS),
            static fn(array $issue): bool => \str_starts_with((string) $issue['type'], 'Tainted') && $issue['file_name'] === $file,
        ));
    }

    private const WARM_CACHE_DIRS = [
        self::FIXTURE . '/.cache/psalm-cached',
        self::FIXTURE . '/.cache/blade-shadows-cached',
    ];

    protected static function resetFixtureAnalyses(): void
    {
        self::deleteTree(self::WARM_CACHE_DIRS);
    }

    /** @param list<string> $directories */
    private static function deleteTree(array $directories): void
    {
        foreach ($directories as $directory) {
            if (!\is_dir($directory)) {
                continue;
            }

            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($entries as $entry) {
                $entry->isDir() && !$entry->isLink() ? \rmdir($entry->getPathname()) : \unlink($entry->getPathname());
            }

            \rmdir($directory);
        }
    }

    private function raw(): string
    {
        return $this->analyzeFixture(self::FIXTURE, self::ARGUMENTS)['output'];
    }

    /** @return iterable<string, array{string, int}> */
    public static function reachingTemplates(): iterable
    {
        yield 'view() helper, no @var' => ['resources/views/search.blade.php', 1];
        yield 'view() helper, @var string' => ['resources/views/typed.blade.php', 2];
        yield 'view()->with(key, value)' => ['resources/views/chained.blade.php', 1];
        yield 'view()->with([array])' => ['resources/views/chained-array.blade.php', 1];
        yield 'View::make()' => ['resources/views/facade.blade.php', 1];
        yield 'Factory::make()' => ['resources/views/factory.blade.php', 1];
        yield 'response()->view()' => ['resources/views/response.blade.php', 1];
        yield 'view() nested in response()' => ['resources/views/nested.blade.php', 1];
        yield 'slash spelling of a dotted name' => ['resources/views/admin/panel.blade.php', 1];
        yield 'namespaced hint' => ['resources/pkg-views/widget.blade.php', 1];
        yield 'only the tainted one of two keys' => ['resources/views/both.blade.php', 1];
    }

    #[Test]
    #[DataProvider('reachingTemplates')]
    public function a_value_passed_to_view_is_tainted_on_the_template_line(string $template, int $line): void
    {
        $issues = $this->taintIssuesIn($template);

        $this->assertCount(1, $issues, $this->raw());
        $this->assertSame('TaintedHtml', $issues[0]['type'], $this->raw());
        $this->assertSame($line, $issues[0]['line_from'], $this->raw());
    }

    /** @return iterable<string, array{string}> */
    public static function cleanTemplates(): iterable
    {
        yield 'literal value' => ['resources/views/literal.blade.php'];
        yield 'other key' => ['resources/views/other-key.blade.php'];
        yield 'escaped echo' => ['resources/views/escaped.blade.php'];
        yield 'dynamic view name' => ['resources/views/dynamic.blade.php'];
        yield 'View::share' => ['resources/views/shared.blade.php'];
        yield 'mailable' => ['resources/views/mailable.blade.php'];
        yield 'Blade-owned name' => ['resources/views/owned.blade.php'];
        yield 'double-underscore name' => ['resources/views/dunder.blade.php'];
    }

    #[Test]
    #[DataProvider('cleanTemplates')]
    public function a_value_that_does_not_flow_to_the_echo_reports_no_taint(string $template): void
    {
        $this->assertSame([], $this->taintIssuesIn($template), $this->raw());
    }

    #[Test]
    public function the_journey_names_the_template_and_the_controller_but_never_the_shadow(): void
    {
        $issues = $this->taintIssuesIn('resources/views/search.blade.php');
        $this->assertCount(1, $issues, $this->raw());

        $trace = $issues[0]['taint_trace'] ?? null;
        $this->assertIsArray($trace, $this->raw());

        $files = \array_column($trace, 'file_name');

        $this->assertContains('resources/views/search.blade.php', $files, $this->raw());
        $this->assertContains('app/Positives.php', $files, $this->raw());
        $this->assertStringNotContainsString('blade-shadows', $this->raw());
    }

    #[Test]
    public function the_bridge_adds_no_non_taint_issue(): void
    {
        $types = [];

        foreach ($this->fixtureIssues(self::FIXTURE, self::ARGUMENTS) as $issue) {
            if (!\str_starts_with((string) $issue['type'], 'Tainted')) {
                $types[] = $issue['type'] . ' ' . $issue['file_name'] . ':' . $issue['line_from'];
            }
        }

        $this->assertSame([], $types, $this->raw());
    }

    #[Test]
    public function a_warm_psalm_and_shadow_cache_still_reports(): void
    {
        self::deleteTree(self::WARM_CACHE_DIRS);

        // No --no-cache: both Psalm's file cache and the shadow cache must be warm on the second step.
        $run = $this->analyzeFixtureSequence(
            self::FIXTURE,
            \array_fill(0, 2, ['-c', 'psalm-cached.xml', '--threads=1', '--no-progress', '--taint-analysis', '--output-format=json']),
        );

        $reported = [];

        foreach ($this->decodeIssues($run) as $issue) {
            if ($issue['type'] === 'TaintedHtml') {
                $reported[] = $issue['file_name'] . ':' . $issue['line_from'];
            }
        }

        \sort($reported);

        $this->assertContains('resources/views/search.blade.php:1', $reported, $run['output']);
        $this->assertContains('resources/views/typed.blade.php:2', $reported, $run['output']);
    }
}
