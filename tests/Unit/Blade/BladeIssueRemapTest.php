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
    public function suppressions_cover_issues_inside_their_own_docblock(): void
    {
        $issues = $this->analyze('psalm.xml');
        $this->assertSame([], $this->linesFor($issues, 'InvalidReturnType', 'docblock-suppression.blade.php'), \json_encode($issues, \JSON_THROW_ON_ERROR));
        $matching = \array_values(\array_filter($issues, static fn(array $issue): bool
            => $issue['type'] === 'InvalidReturnStatement' && \str_ends_with($issue['file_path'], 'docblock-suppression.blade.php')));
        $this->assertCount(1, $matching, \json_encode($matching, \JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('unsuppressed', $matching[0]['message']);
    }

    #[Test]
    public function php_suppressions_remain_local_to_their_statement(): void
    {
        $issues = $this->analyze('psalm.xml');
        foreach (['php-suppression.blade.php', 'raw-suppression.blade.php'] as $template) {
            $lines = $this->linesFor($issues, 'InvalidArgument', $template);
            $this->assertCount(1, $lines, \json_encode($issues, \JSON_THROW_ON_ERROR));
            $this->assertSame([1], $lines);
            foreach ($issues as $issue) {
                if ($issue['type'] === 'InvalidArgument' && \str_ends_with($issue['file_path'], $template)) {
                    $this->assertStringContainsString('list{1}', $issue['message']);
                }
            }
        }
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
        //
        // Line 7 interpolates a variable next to a `(` inside a double-quoted argument, and line 8
        // puts a Blade comment between two arguments. Both are cases where the compiled text and
        // the raw template text diverge INSIDE the call, not just around it.
        $this->assertSame(
            [2, 3, 4, 7, 8],
            $this->linesFor($issues, 'TooManyArguments', 'resources/views/authored-arity.blade.php'),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * A generated call and an author-written call to the same method, with different arguments, on
     * ONE compiled shadow line. Each issue must be judged by the call at its own position: a gate
     * that searched the line for the callee name instead would read the same (first) call for both
     * and either drop both or keep both. The multi-byte text before them is there because the
     * position arithmetic is in bytes.
     */
    #[Test]
    public function two_calls_sharing_one_shadow_line_are_judged_independently(): void
    {
        $issues = $this->analyze('psalm.xml');

        $this->assertSame(
            [2],
            $this->linesFor($issues, 'TooManyArguments', 'resources/views/same-line-arity.blade.php'),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * #1499: `@lang('key')` / `@lang('key', $replace)` compile to
     * `app('translator')->get('key', $replace)`, which without a narrowing provider on
     * `Translator::get()` falls back to the vendor docblock's `string|array` union and
     * reports a `PossiblyInvalidArgument` FP on the compiled echo. `@choice(...)` was
     * already clean (`choice()` returns `string`).
     *
     * The `messages.group` key genuinely resolves to an array (see lang/en/messages.php),
     * so it must NOT be silenced — it becomes a precise `InvalidArgument` instead. That
     * second assertion is the teeth check: it fails both if the fix over-suppresses
     * (silencing instead of narrowing) and if the fixture's lang files never loaded (a
     * vacuous pass on the first assertion alone).
     */
    #[Test]
    public function lang_and_choice_directives_narrow_instead_of_reporting_possibly_invalid_argument(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'resources/views/lang-echo.blade.php';

        $this->assertSame(
            [],
            $this->linesFor($issues, 'PossiblyInvalidArgument', $template),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );

        $this->assertSame(
            [5],
            $this->linesFor($issues, 'InvalidArgument', $template),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * #1500: Blade's compiled output carries its own bookkeeping — the `$__componentOriginal*` /
     * `$__attributesOriginal*` save-and-restore pair around a `<x-...>` tag, and the tail
     * `$loop = $__env->getLastLoop();` reassignment at `@endforeach` — that the template author
     * never wrote and has no way to read back, plus a `@switch` arm's `@break` makes Psalm treat
     * the following `@case`/`@default` line as unreachable. All of that must not surface as
     * `UnusedVariable` / `UnevaluatedCode` on the template.
     *
     * `findUnusedVariablesAndParams` is opt-in (off by default, see `psalm.xml`'s sibling
     * configs), so this needs its own config rather than reusing `psalm.xml`.
     */
    #[Test]
    public function compiler_bookkeeping_unused_code_is_dropped(): void
    {
        $issues = $this->analyze('psalm-unused-code.xml');
        $template = 'resources/views/compiler-bookkeeping.blade.php';

        $this->assertSame(
            [],
            $this->linesFor($issues, 'UnusedVariable', $template),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
        $this->assertSame(
            [],
            $this->linesFor($issues, 'UnevaluatedCode', $template),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );

        // Guard against a vacuous pass: if the `<x-alert>` tag never compiled (fixture missing
        // composer.json breaks Application::getNamespace(), #1500's own trap), the template would
        // report nothing and the assertions above would pass for the wrong reason.
        $this->assertStringContainsString(
            '$__componentOriginal',
            $this->allShadowSources(),
            'the fixture component never compiled: no $__componentOriginal* save found in any compiled shadow',
        );
    }

    /**
     * The negative case for #1500: an author-named `@foreach` variable that is never read is real
     * signal and must keep reporting, even though the compiler's OWN `$loop` bookkeeping next to it
     * is dropped.
     */
    #[Test]
    public function an_unused_foreach_value_still_reports(): void
    {
        $issues = $this->analyze('psalm-unused-code.xml');
        $template = 'resources/views/foreach-unused-value.blade.php';

        $this->assertSame(
            [2],
            $this->linesFor($issues, 'UnusedForeachValue', $template),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * #1505: a vendor package's compiled directive can name a class only in a PHP string literal
     * (`app('Vendor\Package\Class')::method()`), never in code position or a docblock. Psalm's own
     * scanner never sees a string as a class reference, so the class is reported as undefined
     * unless the plugin queues it itself.
     */
    #[Test]
    public function a_class_named_only_in_a_compiled_directives_string_literal_is_not_reported_undefined(): void
    {
        $issues = $this->analyze('psalm.xml');

        foreach ($issues as $issue) {
            if ($issue['type'] === 'UndefinedClass' || $issue['type'] === 'UndefinedDocblockClass') {
                $this->assertStringNotContainsString(
                    'RouteHelperFixture\RouteGenerator',
                    $issue['message'],
                    \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
                );
            }
        }
    }

    /**
     * Same fixture, second run: `ShadowCompiler` never runs on a warm manifest (see
     * `BladeBootstrapper::compileAll()`'s `isFresh()` branch), so the literal-class extraction must
     * come off the shadow FILE on disk, not off the fresh `ShadowResult` the first run produced.
     */
    #[Test]
    public function a_warm_manifest_run_still_queues_the_literal_named_class(): void
    {
        $this->analyze('psalm.xml');
        $issues = $this->analyze('psalm.xml');

        foreach ($issues as $issue) {
            if ($issue['type'] === 'UndefinedClass' || $issue['type'] === 'UndefinedDocblockClass') {
                $this->assertStringNotContainsString(
                    'RouteHelperFixture\RouteGenerator',
                    $issue['message'],
                    \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
                );
            }
        }
    }

    /**
     * Negative: a genuine miss in CODE POSITION must still report; the literal-queueing mechanism
     * must not suppress a real UndefinedClass. (A baseline guard on the whole mechanism — the
     * absent class never appears as a shadow literal, so the `store_failure` flag itself is
     * defense-in-depth here, not what this test pins.)
     */
    #[Test]
    public function a_genuine_undefined_class_in_code_position_still_reports(): void
    {
        $issues = $this->analyze('psalm.xml');

        $this->assertNotSame(
            [],
            \array_filter(
                $issues,
                static fn(array $issue): bool => $issue['type'] === 'UndefinedClass'
                    && \str_contains($issue['message'], 'Totally\Missing\Klass'),
            ),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Negative: a string literal that names no real class must change nothing — no new issue, no
     * crash, and no UndefinedClass minted for a name nothing on disk or in the autoloader answers
     * to.
     */
    #[Test]
    public function a_non_class_string_literal_is_not_queued_or_reported(): void
    {
        $issues = $this->analyze('psalm.xml');

        foreach ($issues as $issue) {
            $this->assertStringNotContainsString(
                'Not\A\Real\ClassName',
                $issue['message'],
                \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
            );
        }
    }

    /**
     * #1505 unused-code guard: queueing with `analyze_too=false` must not enrol the literal-named
     * class in unused-code accounting the way a project-file reference would.
     */
    #[Test]
    public function literal_named_class_queueing_does_not_affect_unused_code_counts(): void
    {
        $issues = $this->analyze('psalm-unused-code.xml');

        $unusedClasses = \array_filter($issues, static fn(array $issue): bool => $issue['type'] === 'UnusedClass');

        foreach ($unusedClasses as $issue) {
            $this->assertStringNotContainsString('RouteHelperFixture\RouteGenerator', $issue['message']);
        }
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
