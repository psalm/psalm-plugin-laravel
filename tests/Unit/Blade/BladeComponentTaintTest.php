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
 */
#[Group('subprocess')]
final class BladeComponentTaintTest extends TestCase
{
    use AnalysesFixtureApp;

    private const FIXTURE = __DIR__ . '/Fixtures/BladeComponentTaint';

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

    private const WARM_CACHE_DIRS = [
        self::FIXTURE . '/.cache/psalm-cached',
        self::FIXTURE . '/.cache/blade-shadows-cached',
    ];

    protected static function resetFixtureAnalyses(): void
    {
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

    private function raw(): string
    {
        return $this->analyzeFixture(self::FIXTURE, self::ARGUMENTS)['output'];
    }

    /** @return iterable<string, array{string}> */
    public static function reachingViews(): iterable
    {
        yield 'tag, promoted constructor property' => ['resources/views/components/alert.blade.php'];
        yield 'tag, plain property assigned in the constructor' => ['resources/views/components/notice.blade.php'];
        yield 'tag, property declared by a parent class' => ['resources/views/components/card.blade.php'];
        yield 'new Foo($input), promoted property' => ['resources/views/components/constructed.blade.php'];
        yield 'new Foo($input), plain property' => ['resources/views/components/constructed-plain.blade.php'];
        yield 'constructor parameter stored under another property name' => ['resources/views/components/renamed.blade.php'];
        yield 'trait property, tag' => ['resources/views/components/trait-a.blade.php'];
        yield 'trait property, new' => ['resources/views/components/trait-d.blade.php'];
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
        yield 'constructor replaces the value' => ['resources/views/components/replaced.blade.php'];
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
        self::resetFixtureAnalyses();

        // No --no-cache: both Psalm's file cache and the shadow cache must be warm on the second step.
        $run = $this->analyzeFixtureSequence(
            self::FIXTURE,
            \array_fill(0, 2, ['-c', 'psalm-cached.xml', '--threads=1', '--no-progress', '--taint-analysis', '--output-format=json']),
        );

        $reported = [];

        foreach ($this->decodeIssues($run) as $issue) {
            if ($issue['type'] === 'TaintedHtml') {
                $reported[] = $issue['file_name'];
            }
        }

        \sort($reported);

        $expected = \array_column(\iterator_to_array(self::reachingViews()), 0);
        \sort($expected);

        $this->assertSame($expected, $reported, $run['output']);
    }
}
