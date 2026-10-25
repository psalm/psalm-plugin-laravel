<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end proof that a value given to a class component's constructor reaches the `{!! !!}` echo in
 * the component's own view (#2033). Only a real `vendor/bin/psalm` run can pin it: the data is written
 * while the page's shadow (or a controller) is analysed and read while the component view's shadow is, in
 * another worker, and the two meet only in the parent's merged graph.
 *
 * Two runs serve every case: a cold single-process run that the per-view cases read, and a second run
 * straight after it, against the Psalm and shadow caches the first left behind and with forked workers,
 * that must report exactly the same findings and journeys.
 */
#[Group('subprocess')]
final class BladeComponentTaintTest extends TestCase
{
    use AnalysesFixtureApp;

    private const FIXTURE = __DIR__ . '/Fixtures/BladeComponentTaint';

    /** No --no-cache: the cold run fills the caches `psalm-cached.xml` points at, for the warm run. */
    private const COLD = ['-c', 'psalm-cached.xml', '--threads=1', '--no-progress', '--taint-analysis', '--output-format=json'];

    /**
     * Forking only happens with more files to analyze than workers; `--debug` prints the pool's
     * "Forking analysis" line that proves it did, and keeps the explicit thread count.
     */
    private const WARM_FORKED = ['-c', 'psalm-cached.xml', '--threads=5', '--scan-threads=5', '--debug', '--no-progress', '--taint-analysis', '--output-format=json'];

    private const WARM_CACHE_DIRS = [
        self::FIXTURE . '/.cache/psalm-cached',
        self::FIXTURE . '/.cache/blade-shadows-cached',
    ];

    /** @var array{cold: array{output: string, errorOutput: string, shadows: string}, warm: array{output: string, errorOutput: string, shadows: string}}|null */
    private static ?array $runs = null;

    protected static function resetFixtureAnalyses(): void
    {
        self::$runs = null;

        foreach (self::WARM_CACHE_DIRS as $directory) {
            self::deleteTree($directory);
        }
    }

    private static function deleteTree(string $directory): void
    {
        if (!\is_dir($directory)) {
            return;
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

    /**
     * Both runs in one call, so nothing can touch the caches between them whatever order the cases run in.
     *
     * @return array{cold: array{output: string, errorOutput: string, shadows: string}, warm: array{output: string, errorOutput: string, shadows: string}}
     */
    private function runs(): array
    {
        if (self::$runs !== null) {
            return self::$runs;
        }

        self::resetFixtureAnalyses();

        $cold = $this->analyzeFixture(self::FIXTURE, self::COLD);

        return self::$runs = ['cold' => $cold, 'warm' => $this->analyzeFixture(self::FIXTURE, self::WARM_FORKED)];
    }

    /**
     * The taint issues of one file in the cold run: every `Tainted*` type, or just the one named. An
     * unescaped echo reports `TaintedHtml` and `TaintedTextWithQuotes` together, so a count needs a type.
     *
     * @return list<array<string, mixed>>
     */
    private function taintIssuesIn(string $file, ?string $type = null): array
    {
        return \array_values(\array_filter(
            $this->decodeIssues($this->runs()['cold']),
            static fn(array $issue): bool => $issue['file_name'] === $file
                && ($type === null ? \str_starts_with((string) $issue['type'], 'Tainted') : $issue['type'] === $type),
        ));
    }

    private function raw(): string
    {
        return $this->runs()['cold']['output'];
    }

    /**
     * Every issue as one sortable line, journey steps included: a taint finding is only equal if its
     * whole journey is.
     *
     * @param list<array<string, mixed>> $issues
     *
     * @return list<string>
     */
    private function findings(array $issues): array
    {
        $findings = [];

        foreach ($issues as $issue) {
            $steps = [];

            foreach (\is_array($issue['taint_trace'] ?? null) ? $issue['taint_trace'] : [] as $step) {
                $steps[] = \is_array($step)
                    ? \sprintf('%s@%s:%s:%s', (string) ($step['label'] ?? '?'), (string) ($step['file_name'] ?? '?'), (string) ($step['line_from'] ?? '?'), (string) ($step['column_from'] ?? '?'))
                    : '?';
            }

            $findings[] = \sprintf(
                '%s:%d:%d %s %s | %s',
                (string) $issue['file_name'],
                (int) $issue['line_from'],
                (int) $issue['column_from'],
                (string) $issue['type'],
                (string) $issue['message'],
                \implode(' > ', $steps),
            );
        }

        \sort($findings);

        return $findings;
    }

    /** @return iterable<string, array{string}> */
    public static function reachingViews(): iterable
    {
        yield 'tag, promoted constructor property' => ['resources/views/components/alert.blade.php'];
        yield 'tag, property declared by a parent class' => ['resources/views/components/card.blade.php'];
        yield 'new Foo($input)' => ['resources/views/components/constructed.blade.php'];
        yield 'constructor parameter stored under another property name' => ['resources/views/components/renamed.blade.php'];
        yield 'trait property' => ['resources/views/components/trait-a.blade.php'];
        yield 'constructor declared in a trait' => ['resources/views/components/trait-ctor.blade.php'];
        yield 'constructor declared in a trait of the parent class' => ['resources/views/components/trait-ctor-child.blade.php'];
        yield '@component(Foo::class, ...)' => ['resources/views/components/compo.blade.php'];
        yield 'render() passing non-literal data' => ['resources/views/components/extra.blade.php'];
        yield 'view shared by two classes, one tainted' => ['resources/views/components/shared-x.blade.php'];
        // Accepted over-report (docs/contributing/blade.md): a property declared by a shared base class is
        // one node for every subclass, so Card's tainted tag reaches SibB's view.
        yield 'shared base class, sibling with a literal (over-report)' => ['resources/views/components/sib-b.blade.php'];
    }

    #[Test]
    #[DataProvider('reachingViews')]
    public function a_component_property_is_tainted_on_the_view_line(string $view): void
    {
        $issues = $this->taintIssuesIn($view, 'TaintedHtml');

        $this->assertCount(1, $issues, $this->raw());
        $this->assertSame(1, $issues[0]['line_from'], $this->raw());
    }

    /** @return iterable<string, array{string}> */
    public static function cleanViews(): iterable
    {
        yield 'escaped echo' => ['resources/views/components/escaped.blade.php'];
        yield 'literal attribute' => ['resources/views/components/literal.blade.php'];
        yield 'non-public property' => ['resources/views/components/hidden.blade.php'];
        yield 'class whose render() maps to no view' => ['resources/views/components/unmapped.blade.php'];
        yield 'constructor escapes the value' => ['resources/views/components/san-e.blade.php'];
        yield 'tag key that is not a constructor parameter' => ['resources/views/components/unbound.blade.php'];
        yield 'trait property of an unrelated component using the same trait' => ['resources/views/components/trait-b.blade.php'];
        yield 'literal $except hides the property' => ['resources/views/components/except-lit.blade.php'];
        yield '$slot property, replaced by the rendered slot' => ['resources/views/components/holder.blade.php'];
        yield 'static property' => ['resources/views/components/static-prop.blade.php'];
        yield 'name Component::data() ignores' => ['resources/views/components/ignored-name.blade.php'];
        // `Lookalike::resolve(['message' => request('m')])` sits in the page; its `show()` echoes the property.
        yield 'same-shaped class that is not a component' => ['app/Support/Lookalike.php'];
    }

    #[Test]
    #[DataProvider('cleanViews')]
    public function a_value_that_does_not_reach_the_echo_reports_no_taint(string $view): void
    {
        $this->assertSame([], $this->taintIssuesIn($view), $this->raw());
    }

    #[Test]
    public function the_journey_names_the_page_and_the_view_but_never_the_shadow(): void
    {
        $issues = $this->taintIssuesIn('resources/views/components/alert.blade.php', 'TaintedHtml');
        $this->assertCount(1, $issues, $this->raw());

        $trace = $issues[0]['taint_trace'] ?? null;
        $this->assertIsArray($trace, $this->raw());

        $files = \array_column($trace, 'file_name');

        $this->assertContains('resources/views/components/alert.blade.php', $files, $this->raw());
        $this->assertContains('resources/views/page.blade.php', $files, $this->raw());
        $this->assertStringNotContainsString('blade-shadows', $this->raw());
    }

    #[Test]
    public function the_bridge_adds_no_non_taint_issue(): void
    {
        $types = [];

        foreach ($this->decodeIssues($this->runs()['cold']) as $issue) {
            if (!\str_starts_with((string) $issue['type'], 'Tainted')) {
                $types[] = $issue['type'] . ' ' . $issue['file_name'] . ':' . $issue['line_from'];
            }
        }

        $this->assertSame([], $types, $this->raw());
    }

    /**
     * The page shadow writes the constructor argument and the component view's shadow reads the
     * property, so with forked workers the two halves meet only in the parent's merged graph; the warm
     * caches add the shadow manifest and Psalm's file cache. A failure here names neither cause alone:
     * rerun the second step with `--threads=1` to tell them apart.
     */
    #[Test]
    public function a_warm_cache_forked_run_reports_exactly_the_cold_findings_and_journeys(): void
    {
        // Psalm silently clamps --threads to 1 on Windows or without pcntl, so the fork proof would fail.
        if (\defined('PHP_WINDOWS_VERSION_MAJOR') || !\extension_loaded('pcntl')) {
            self::markTestSkipped('Psalm cannot fork analysis workers in this environment.');
        }

        ['cold' => $cold, 'warm' => $warm] = $this->runs();

        $this->assertStringContainsString(
            'Forking analysis',
            $warm['output'] . $warm['errorOutput'],
            'The warm run never forked, so it proves nothing about workers.',
        );

        $coldIssues = $this->decodeIssues($cold);
        $this->assertNotSame([], $this->taintIssuesIn('resources/views/components/alert.blade.php', 'TaintedHtml'), 'Equality proves nothing without the bridged finding.');

        foreach ($coldIssues as $issue) {
            if (\str_starts_with((string) $issue['type'], 'Tainted')) {
                $this->assertNotEmpty($issue['taint_trace'] ?? null, 'A cold taint finding carries no journey, so equality would hold by vacuity.');
            }
        }

        $this->assertSame($this->findings($coldIssues), $this->findings($this->decodeIssues($warm)), $warm['output']);
        $this->assertStringNotContainsString('blade-shadows', $warm['output']);
    }
}
