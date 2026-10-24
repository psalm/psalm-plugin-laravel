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
     * The taint issues of one file: every `Tainted*` type, or just the one named. An unescaped echo
     * reports `TaintedHtml` and `TaintedTextWithQuotes` together, so a count needs a type.
     *
     * @return list<array<string, mixed>>
     */
    private function taintIssuesIn(string $file, ?string $type = null): array
    {
        return \array_values(\array_filter(
            $this->fixtureIssues(self::FIXTURE, self::ARGUMENTS),
            static fn(array $issue): bool => $issue['file_name'] === $file
                && ($type === null ? \str_starts_with((string) $issue['type'], 'Tainted') : $issue['type'] === $type),
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
        yield 'view typed by a class component seed' => ['resources/views/components/banner.blade.php', 2];
        yield 'only the tainted one of two keys' => ['resources/views/both.blade.php', 1];
        yield 'mergeData by name on a factory' => ['resources/views/merge-named.blade.php', 1];
        yield 'mergeData positional on the helper' => ['resources/views/merge-helper.blade.php', 1];
        yield 'mergeData by name on the helper' => ['resources/views/merge-helper-named.blade.php', 1];
        yield 'mergeData positional on the facade' => ['resources/views/merge-facade.blade.php', 1];
        yield 'view name padded with whitespace' => ['resources/views/padded.blade.php', 1];
        yield 'userland factory subclass that overrides nothing' => ['resources/views/subclass-plain.blade.php', 1];
        yield 'data beats mergeData for a shared key' => ['resources/views/data-over-merge.blade.php', 1];
        yield 'open spread after the key' => ['resources/views/spread-open.blade.php', 1];
        yield 'literal spread carrying the tainted value last' => ['resources/views/spread-literal-taint.blade.php', 1];
        // Accepted over-report: a later `with()` of the same key is not seen by the inner link (docs/contributing/blade.md).
        yield 'overwritten by a later with() link (over-report)' => ['resources/views/overwritten-chain.blade.php', 1];
        yield 'name Blade also shares' => ['resources/views/owned.blade.php', 1];
        yield 'double-underscore name' => ['resources/views/dunder.blade.php', 1];
        yield 'data array held in a variable' => ['resources/views/var-array.blade.php', 1];
        yield 'compact()' => ['resources/views/compacted.blade.php', 1];
        yield 'with() of an array variable' => ['resources/views/with-array-var.blade.php', 1];
        yield 'untyped request array' => ['resources/views/untyped-array.blade.php', 1];
        yield 'mergeData held in a variable' => ['resources/views/merge-array-var.blade.php', 1];
        yield '@include data' => ['resources/views/included.blade.php', 1];
        yield '@includeIf data' => ['resources/views/inc-if.blade.php', 1];
        yield '@includeWhen data' => ['resources/views/inc-when.blade.php', 1];
        yield '@includeUnless data' => ['resources/views/inc-unless.blade.php', 1];
        yield '@includeFirst data' => ['resources/views/inc-first.blade.php', 1];
        yield '@extends data' => ['resources/views/inc-extends.blade.php', 1];
        yield '@include data held in a variable' => ['resources/views/inc-var.blade.php', 1];
        yield 'variable the includer assigned' => ['resources/views/scope-partial.blade.php', 1];
        yield 'variable the includer received as view data' => ['resources/views/scope-data-partial.blade.php', 1];
        yield 'anonymous component prop' => ['resources/views/components/card.blade.php', 1];
        yield 'anonymous component: only the tainted prop' => ['resources/views/components/card-sibling.blade.php', 2];
        yield '`*` key beside a real one' => ['resources/views/star-key-sibling.blade.php', 1];
        yield '@includeFirst of a namespaced candidate' => ['resources/pkg-views/second.blade.php', 1];
        yield '@includeFirst past a namespaced candidate of unknown existence' => ['resources/views/inc-first-b.blade.php', 1];
    }

    #[Test]
    #[DataProvider('reachingTemplates')]
    public function a_value_passed_to_view_is_tainted_on_the_template_line(string $template, int $line): void
    {
        $issues = $this->taintIssuesIn($template, 'TaintedHtml');

        $this->assertCount(1, $issues, $this->raw());
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
        yield 'later literal spread key overwrites it' => ['resources/views/overwritten.blade.php'];
        yield 'data overwrites mergeData' => ['resources/views/merge-overwritten.blade.php'];
        yield 'unrelated namespaced function named view' => ['resources/views/probe-fn.blade.php'];
        yield 'imported unrelated function named view' => ['resources/views/probe-imported.blade.php'];
        yield 'userland factory overriding make()' => ['resources/views/custom-factory.blade.php'];
        yield 'userland response factory overriding view()' => ['resources/views/custom-response.blade.php'];
        yield 'data array variable without taint' => ['resources/views/clean-array.blade.php'];
        yield '@include of another key' => ['resources/views/inc-other-key.blade.php'];
        yield '@includeIsolated inherits nothing' => ['resources/views/inc-isolated.blade.php'];
        yield '@include of a literal value' => ['resources/views/inc-literal.blade.php'];
        yield 'includer variable without taint' => ['resources/views/scope-clean-partial.blade.php'];
        yield 'includer that only echoes the value escaped' => ['resources/views/scope-data-includer.blade.php'];
        yield 'look-alike include outside a template' => ['resources/views/lookalike-php.blade.php'];
        yield 'look-alike make() on another receiver in a template' => ['resources/views/lk-partial.blade.php'];
        yield '@includeWhen(false) data' => ['resources/views/off-when.blade.php'];
        yield '@includeWhen(false) scope' => ['resources/views/off-when-scope.blade.php'];
        yield '@includeUnless(true) data' => ['resources/views/off-unless.blade.php'];
        yield '@includeUnless(true) scope' => ['resources/views/off-unless-scope.blade.php'];
        yield 'author filter that removes the variable from the scope' => ['resources/views/filtered-scope-partial.blade.php'];
        yield '`*` view data key' => ['resources/views/star-key.blade.php'];
        yield '@includeFirst candidate after the first existing one' => ['resources/views/inc-first-never.blade.php'];
    }

    #[Test]
    #[DataProvider('cleanTemplates')]
    public function a_value_that_does_not_flow_to_the_echo_reports_no_taint(string $template): void
    {
        $this->assertSame([], $this->taintIssuesIn($template), $this->raw());
    }

    #[Test]
    public function an_escaped_attribute_bag_next_to_a_tainted_prop_reports_no_taint(): void
    {
        $lines = \array_unique(\array_column($this->taintIssuesIn('resources/views/components/card.blade.php'), 'line_from'));

        $this->assertSame([1], \array_values($lines), $this->raw());
    }

    #[Test]
    public function the_journey_names_the_template_and_the_controller_but_never_the_shadow(): void
    {
        $issues = $this->taintIssuesIn('resources/views/search.blade.php', 'TaintedHtml');
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
        $this->assertContains('resources/views/var-array.blade.php:1', $reported, $run['output']);
        $this->assertContains('resources/views/included.blade.php:1', $reported, $run['output']);
        $this->assertContains('resources/views/scope-partial.blade.php:1', $reported, $run['output']);
        $this->assertContains('resources/views/components/card.blade.php:1', $reported, $run['output']);
    }
}
