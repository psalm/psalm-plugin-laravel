<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\BladeIssueRemapHandler;
use Psalm\LaravelPlugin\Blade\ShadowIssueRelocator;
use Psalm\LaravelPlugin\Blade\ShadowRegistry;
use Symfony\Component\Process\Process;

/**
 * End-to-end proof that an issue Psalm finds in a shadow file surfaces on the `.blade.php` path and
 * line instead. A real `vendor/bin/psalm` run is the only way to pin it: the remap depends on Psalm
 * dispatching `BeforeAddIssue` before its reportability and suppression gates, and on the template
 * having been written into `ProjectAnalyzer`'s private project-file list at boot.
 */
#[CoversClass(BladeIssueRemapHandler::class)]
#[CoversClass(ShadowIssueRelocator::class)]
#[CoversClass(ShadowRegistry::class)]
final class BladeIssueRemapTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/Fixtures/BladeIssueRemap';

    private const SHADOW_DIR = self::FIXTURE . '/.cache/blade-shadows';

    private const ISSUE = 'UndefinedPropertyFetch';

    protected function setUp(): void
    {
        $this->deleteShadowDir();
    }

    protected function tearDown(): void
    {
        $this->deleteShadowDir();
    }

    private function deleteShadowDir(): void
    {
        if (!\is_dir(self::SHADOW_DIR)) {
            return;
        }

        foreach (\array_diff(\scandir(self::SHADOW_DIR) ?: [], ['.', '..']) as $entry) {
            \unlink(self::SHADOW_DIR . '/' . $entry);
        }

        \rmdir(self::SHADOW_DIR);
    }

    /**
     * @return list<array{type: string, file_path: string, line_from: int, message: string}>
     */
    private function analyze(string $config): array
    {
        $psalmBinary = \dirname(__DIR__, 3) . '/vendor/bin/psalm';
        $this->assertFileExists($psalmBinary, 'Psalm binary not found — run composer install.');

        $process = new Process(
            [\PHP_BINARY, $psalmBinary, '-c', $config, '--no-cache', '--threads=1', '--no-progress', '--output-format=json'],
            self::FIXTURE,
        );
        $process->setTimeout(300);
        // Not mustRun(): the fixture reports issues on purpose.
        $process->run();

        $decoded = \json_decode($process->getOutput(), true);
        $this->assertIsArray($decoded, "Psalm did not emit a JSON report.\n{$process->getOutput()}\n{$process->getErrorOutput()}");

        $issues = [];

        foreach ($decoded as $issue) {
            $this->assertIsArray($issue);
            $issues[] = [
                'type' => (string) $issue['type'],
                'file_path' => (string) $issue['file_path'],
                'line_from' => (int) $issue['line_from'],
                'message' => (string) $issue['message'],
            ];
        }

        return $issues;
    }

    /**
     * @param list<array{type: string, file_path: string, line_from: int, message: string}> $issues
     *
     * @return list<int> the lines the issue type was reported on for that template
     */
    private function linesFor(array $issues, string $type, string $template): array
    {
        $lines = [];

        foreach ($issues as $issue) {
            if ($issue['type'] === $type && \str_ends_with($issue['file_path'], $template)) {
                $lines[] = $issue['line_from'];
            }
        }

        return $lines;
    }

    #[Test]
    public function a_shadow_issue_is_reported_on_the_template_path_and_line(): void
    {
        $issues = $this->analyze('psalm.xml');

        $this->assertSame(
            [2],
            $this->linesFor($issues, self::ISSUE, 'resources/views/broken.blade.php'),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );

        // Nothing may still be reported against the compiled shadow itself.
        foreach ($issues as $issue) {
            $this->assertStringNotContainsString('blade-shadows', $issue['file_path']);
        }
    }

    #[Test]
    public function a_warm_manifest_run_reports_the_same_template_line(): void
    {
        $this->analyze('psalm.xml');

        // Second run: every template is a manifest freshness hit, so the line map and the
        // suppression map must come off the manifest rather than a recompile.
        $issues = $this->analyze('psalm.xml');

        $this->assertSame(
            [2],
            $this->linesFor($issues, self::ISSUE, 'resources/views/broken.blade.php'),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /** The prelude's ambient Blade variables, see PreludeBuilder::AMBIENT_TYPES. */
    private const AMBIENT_CLASSES = [
        'Illuminate\View\Factory',
        'Illuminate\Support\ViewErrorBag',
        'Illuminate\View\ComponentAttributeBag',
        'Illuminate\View\ComponentSlot',
        'Illuminate\View\Component',
    ];

    #[Test]
    public function ambient_prelude_classes_never_report_undefined(): void
    {
        $issues = $this->analyze('psalm.xml');

        foreach ($issues as $issue) {
            if ($issue['type'] !== 'UndefinedDocblockClass') {
                continue;
            }

            foreach (self::AMBIENT_CLASSES as $ambientClass) {
                $this->assertStringNotContainsString(
                    $ambientClass,
                    $issue['message'],
                    \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
                );
            }
        }
    }

    #[Test]
    public function an_issue_handler_suppression_on_the_view_directory_silences_the_template(): void
    {
        $issues = $this->analyze('psalm-views-suppressed.xml');

        $this->assertSame(
            [],
            $this->linesFor($issues, self::ISSUE, '.blade.php'),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function an_inline_psalm_suppress_comment_silences_the_line_below_it(): void
    {
        $issues = $this->analyze('psalm.xml');

        $this->assertSame(
            [],
            $this->linesFor($issues, self::ISSUE, 'resources/views/suppressed.blade.php'),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function the_mixed_issue_family_is_suppressed_by_default(): void
    {
        $issues = $this->analyze('psalm.xml');

        $this->assertSame(
            [],
            $this->linesFor($issues, 'MixedArgument', 'resources/views/untyped.blade.php'),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );

        foreach ($issues as $issue) {
            if (\str_starts_with($issue['type'], 'Mixed')) {
                $this->assertStringNotContainsString(
                    '.blade.php',
                    $issue['file_path'],
                    \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
                );
            }
        }
    }

    #[Test]
    public function reportmixedissues_reinstates_the_family_on_a_warm_manifest(): void
    {
        // Warm the manifest with the default (off) config first: reusing it under the opt-in config
        // is itself the proof that the flag needs no cache invalidation.
        $this->analyze('psalm.xml');
        $issues = $this->analyze('psalm-blade-report-mixed.xml');

        $this->assertSame(
            [2],
            $this->linesFor($issues, 'MixedArgument', 'resources/views/untyped.blade.php'),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Livewire-style precompiled tags fire an untyped `$__split` closure and an over-arity method
     * call on the SAME (mapped) template line, both dropped at shadow emission (#1498) unless the
     * over-arity call is one the template author actually wrote.
     */
    #[Test]
    public function livewire_style_generated_closure_and_arity_issues_are_dropped_but_an_authored_one_survives(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'resources/views/livewire-fp.blade.php';

        $this->assertSame(
            [],
            $this->linesFor($issues, 'MissingClosureParamType', $template),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
        $this->assertSame(
            [],
            $this->linesFor($issues, 'MissingClosureReturnType', $template),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );

        // The author-written call is the one line the template actually has; the generated call
        // has no line of its own, so both would collapse onto it if the gate failed open the
        // wrong way. One survivor here, on the real line, is the only way to tell them apart.
        $this->assertSame(
            [3],
            $this->linesFor($issues, 'TooManyArguments', $template),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );

        // Guard against a vacuous pass: if LivewireStubProvider::boot() silently failed to run
        // (a broken fixture provider, BladeBootstrapper degrading), the assertions above would
        // pass for the wrong reason — nothing generated, nothing to suppress. The registry lives
        // in the subprocess that just exited, so the only thing left to inspect is the cache
        // directory itself. Both halves of the generated shape are pinned: the untyped closure
        // that carries the two MissingClosure* issues, and the 5-argument call that carries the
        // TooManyArguments the gate has to drop.
        $shadows = $this->allShadowSources();

        $this->assertStringContainsString(
            '$__split = function ($__id, $__params)',
            $shadows,
            'the fixture precompiler never ran: no generated closure found in any compiled shadow',
        );
        $this->assertStringContainsString(
            "->mount('id', 'params', 'key', 'extra1', 'extra2')",
            $shadows,
            'the fixture precompiler never ran: no generated over-arity call in any compiled shadow',
        );
    }

    /**
     * Blade REWRITES the lines it compiles, so a gate that matched generated shadow lines against
     * the raw template verbatim only ever recognised `<?php ?>` blocks as author-written. Every
     * other compiled syntax (`{{ }}`, `{!! !!}`, `@php`) turns into `echo`/`e()` text the template
     * does not contain, and a genuine author over-arity call inside one was dropped as "generated"
     * (#1498).
     */
    #[Test]
    public function an_authored_over_arity_call_survives_in_every_compiled_blade_syntax(): void
    {
        $issues = $this->analyze('psalm.xml');

        // Lines 2 and 3 are the `{{ }}` and `{!! !!}` calls. The `@php` call is written on line 5
        // but reports on 4: a multi-line construct's continuation lines get no marker of their own
        // (see MarkerPrePass::computeSkipLines()), so the body inherits the `@php` line. That is
        // pre-existing line-map behaviour, not something this gate decides.
        $this->assertSame(
            [2, 3, 4],
            $this->linesFor($issues, 'TooManyArguments', 'resources/views/authored-arity.blade.php'),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /** Every compiled shadow's source, concatenated, read before tearDown() wipes the cache dir. */
    private function allShadowSources(): string
    {
        $this->assertDirectoryExists(self::SHADOW_DIR, 'no shadow was ever written for this run');

        $source = '';

        foreach (\array_diff(\scandir(self::SHADOW_DIR) ?: [], ['.', '..']) as $entry) {
            $source .= (string) \file_get_contents(self::SHADOW_DIR . '/' . $entry);
        }

        return $source;
    }
}
