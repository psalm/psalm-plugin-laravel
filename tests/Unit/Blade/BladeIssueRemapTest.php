<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\BladeIssueRemapHandler;
use Psalm\LaravelPlugin\Blade\ShadowIssueRelocator;
use Psalm\LaravelPlugin\Blade\ShadowRegistry;

/**
 * End-to-end proof that an issue Psalm finds in a shadow file surfaces on the `.blade.php` path and
 * line instead. A real `vendor/bin/psalm` run is the only way to pin it: the remap depends on Psalm
 * dispatching `BeforeAddIssue` before its reportability and suppression gates, and on the template
 * having been written into `ProjectAnalyzer`'s private project-file list at boot.
 */
#[CoversClass(BladeIssueRemapHandler::class)]
#[CoversClass(ShadowIssueRelocator::class)]
#[CoversClass(ShadowRegistry::class)]
#[Group('subprocess')]
final class BladeIssueRemapTest extends TestCase
{
    use AnalysesFixtureApp;

    private const FIXTURE = __DIR__ . '/Fixtures/BladeIssueRemap';

    private const ISSUE = 'UndefinedPropertyFetch';

    /**
     * The command a case's `$config` resolves to. Cases naming the same config share one run.
     *
     * @return list<string>
     */
    private function arguments(string $config): array
    {
        return ['-c', $config, '--no-cache', '--threads=1', '--no-progress', '--output-format=json'];
    }

    /**
     * @return list<array{type: string, file_path: string, line_from: int, message: string}>
     */
    private function analyze(string $config): array
    {
        return $this->project($this->fixtureIssues(self::FIXTURE, $this->arguments($config)));
    }

    /**
     * The final run of consecutive real runs, each against the shadow directory its predecessor
     * left behind. A warm-manifest case has to keep both subprocesses: the second one reading the
     * first one's manifest is the whole assertion.
     *
     * @param non-empty-list<string> $configs
     *
     * @return list<array{type: string, file_path: string, line_from: int, message: string}>
     */
    private function analyzeWarm(array $configs): array
    {
        $argumentSets = \array_map(fn(string $config): array => $this->arguments($config), $configs);

        return $this->project($this->decodeIssues($this->analyzeFixtureSequence(self::FIXTURE, $argumentSets)));
    }

    /**
     * @param list<array<string, mixed>> $issues
     *
     * @return list<array{type: string, file_path: string, line_from: int, message: string}>
     */
    private function project(array $issues): array
    {
        $projected = [];

        foreach ($issues as $issue) {
            $projected[] = [
                'type' => (string) $issue['type'],
                'file_path' => (string) $issue['file_path'],
                'line_from' => (int) $issue['line_from'],
                'message' => (string) $issue['message'],
            ];
        }

        return $projected;
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

    /**
     * @param list<array{type: string, file_path: string, line_from: int, message: string}> $issues
     *
     * @return list<string> the messages the issue type was reported with for that template
     */
    private function messagesFor(array $issues, string $type, string $template): array
    {
        $messages = [];

        foreach ($issues as $issue) {
            if ($issue['type'] === $type && \str_ends_with($issue['file_path'], $template)) {
                $messages[] = $issue['message'];
            }
        }

        return $messages;
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

    /**
     * The suppressed and unsuppressed calls sit on adjacent lines of one block. Before #1544 both
     * collapsed onto the line the block opened on, so "local to their statement" could only be read
     * off the issue COUNT; now the surviving one reports on the line it was written on.
     */
    #[Test]
    public function php_suppressions_remain_local_to_their_statement(): void
    {
        $issues = $this->analyze('psalm.xml');
        foreach (['php-suppression.blade.php' => 4, 'raw-suppression.blade.php' => 5] as $template => $line) {
            $lines = $this->linesFor($issues, 'InvalidArgument', $template);
            $this->assertCount(1, $lines, \json_encode($issues, \JSON_THROW_ON_ERROR));
            $this->assertSame([$line], $lines);
            foreach ($issues as $issue) {
                if ($issue['type'] === 'InvalidArgument' && \str_ends_with($issue['file_path'], $template)) {
                    $this->assertStringContainsString('list{1}', $issue['message']);
                }
            }
        }
    }

    /**
     * #1544: a `@php`/raw-`<?php` body used to get no marker of its own, so every statement in it
     * inherited the line the block OPENED on. Four statements on four distinct lines, across both
     * block syntaxes, is the shape that collapsed onto two.
     */
    #[Test]
    public function every_statement_of_a_php_block_reports_on_its_own_line(): void
    {
        $issues = $this->analyze('psalm.xml');

        $this->assertSame(
            [3, 4, 7, 8],
            $this->linesFor($issues, 'InvalidArgument', 'resources/views/php-block-lines.blade.php'),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
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
        // Second run: every template is a manifest freshness hit, so the line map and the
        // suppression map must come off the manifest rather than a recompile.
        $issues = $this->analyzeWarm(['psalm.xml', 'psalm.xml']);

        $this->assertSame(
            [2],
            $this->linesFor($issues, self::ISSUE, 'resources/views/broken.blade.php'),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Every class name PreludeBuilder can emit into a prelude, see PreludeBuilder::ambientClassNames().
     * `Illuminate\View\Component` appears in a caller's prelude from tag bookkeeping (#1873).
     */
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
        $issues = $this->analyzeWarm(['psalm.xml', 'psalm-blade-report-mixed.xml']);

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

        // Lines 2 and 3 are the `{{ }}` and `{!! !!}` calls; line 5 is the one inside `@php`, which
        // carries a bare marker of its own since #1544 and no longer reports on the `@php` line.
        //
        // Line 7 interpolates a variable next to a `(` inside a double-quoted argument, and line 8
        // puts a Blade comment between two arguments. Both are cases where the compiled text and
        // the raw template text diverge INSIDE the call, not just around it. Line 9 escapes a
        // literal `@foo` as `@@foo`; Blade's compileStatements() unescapes it to `@foo` before the
        // call reaches the shadow, so the call's own argument text differs from the template too
        // (#1540).
        // Lines 11 and 19 are calls written ACROSS lines inside `@php`, so every continuation line
        // of each argument list carries a bare marker (#1544), and both report on the line their
        // callee sits on. Line 19 is the one with teeth for the marker strip: Psalm locates a
        // FUNCTION call's `TooManyArguments` on the whole call node, so `getSnippet()` spans all
        // four of its lines, `callExpressionAt()` reads the argument list to its end, and the
        // markers inside it reach the template comparison. Remove the strip and this line's real
        // author finding is judged compiler-generated and dropped. Line 11 cannot stand in for it:
        // a METHOD call is located on its name node alone, so the snippet is one line, the argument
        // list never closes inside it, and the gate declines before comparing anything.
        $this->assertSame(
            [2, 3, 5, 7, 8, 9, 11, 19],
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
            $this->allShadowSources('psalm-unused-code.xml'),
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
        $issues = $this->analyzeWarm(['psalm.xml', 'psalm.xml']);

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

    /** #1525/#1532's five ambient-guard families, checked together against one template. */
    private const AMBIENT_GUARD_FAMILIES = [
        'RedundantCondition',
        'RedundantConditionGivenDocblockType',
        'DocblockTypeContradiction',
        'TypeDoesNotContainNull',
        'TypeDoesNotContainType',
    ];

    /**
     * #1525 acceptance (a) + (b): `compiler-bookkeeping.blade.php` is a plain CALLER template
     * (`<x-alert>Hi</x-alert>` on line 1, no `@props`/`@aware`/`$attributes`/`$slot` of its own),
     * so its prelude types `$component` and `$attributes` only from the tag's bookkeeping, nullable
     * (#1873). The compiled `isset()` guards stay undecidable; the `instanceof` after the `isset()`
     * is redundant and dropped by its compiled-shape gate.
     */
    #[Test]
    public function ambient_guards_in_a_non_component_caller_report_nothing(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'resources/views/compiler-bookkeeping.blade.php';

        foreach (self::AMBIENT_GUARD_FAMILIES as $family) {
            $this->assertSame(
                [],
                $this->linesFor($issues, $family, $template),
                \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
            );
        }

        // Guard against a vacuous pass: the tag must have actually compiled.
        $this->assertStringContainsString('$__componentOriginal', $this->allShadowSources());
    }

    /**
     * #1873: a plain caller's `<x-...>` save/restore reads only names the prelude types from that
     * bookkeeping (`$component`, `$attributes`, each saved original), so the tag's own line reports
     * no `Mixed*` and no ambient guard, single and nested. Under `psalm-blade-report-mixed.xml`:
     * the default config drops every `MixedIssue`.
     */
    #[Test]
    public function a_plain_callers_tag_bookkeeping_is_typed(): void
    {
        $issues = $this->analyze('psalm-blade-report-mixed.xml');

        foreach (['resources/views/compiler-bookkeeping.blade.php', 'resources/views/nested-component-tags.blade.php'] as $template) {
            $onTagLine = \array_values(\array_filter(
                $issues,
                static fn(array $issue): bool => \str_ends_with($issue['file_path'], $template)
                    && $issue['line_from'] === 1
                    && (\str_starts_with($issue['type'], 'Mixed') || \in_array($issue['type'], self::AMBIENT_GUARD_FAMILIES, true)),
            ));

            $this->assertSame([], $onTagLine, \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
            $this->assertStringContainsString(
                '@var \Illuminate\View\Component|null $component */',
                $this->shadowSourceFor($template, 'psalm-blade-report-mixed.xml'),
            );
        }
    }

    /**
     * #1543: {@see \Psalm\LaravelPlugin\Blade\AttributesRestoreReassert} must not fire on a plain
     * caller — `compiler-bookkeeping.blade.php` carries a real `<x-alert>` tag's compiled
     * `$__attributesOriginal*` save/restore pair, so a gate-less injection would still find
     * something to match here, even though the template itself never mentions `@props`/`@aware`/
     * `$attributes`/`$slot` and `isComponentView()` is false for it (#1543 review must-fix 1: the
     * previous `"Hello {{ \$name }}\n"` fixture compiled to no restore/strip block at all, so it
     * passed with the `isComponentView()` gate deleted).
     */
    #[Test]
    public function a_plain_caller_with_a_component_tag_gets_no_attributes_reassert(): void
    {
        $this->analyze('psalm.xml');
        $template = 'resources/views/compiler-bookkeeping.blade.php';
        $shadow = $this->shadowSourceFor($template);

        // Guard against a vacuous pass: the tag must have actually compiled its restore pair.
        $this->assertStringContainsString('$__attributesOriginal', $shadow);

        $this->assertStringNotContainsString('endif; /** @var', $shadow);
    }

    /**
     * #1525 acceptance (c): `$attributes->merge()` and `$slot` stay typed in a plain `@props`
     * component view. Run under `psalm-blade-report-mixed.xml`, not the default `psalm.xml`:
     * `MixedIssue` (which `MixedMethodCall` implements) is dropped unconditionally when
     * `reportMixedIssues` is off (#1495), so that assertion is vacuous under the default config —
     * it would pass whether or not `$attributes`/`$slot` got a real type.
     */
    #[Test]
    public function attributes_merge_and_slot_stay_typed_in_a_props_component_view(): void
    {
        $issues = $this->analyze('psalm-blade-report-mixed.xml');
        $template = 'components/typed-attributes.blade.php';

        foreach (['PossiblyNullReference', 'UndefinedGlobalVariable', 'MixedMethodCall'] as $family) {
            $this->assertSame(
                [],
                $this->linesFor($issues, $family, $template),
                \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
            );
        }

        foreach (self::AMBIENT_GUARD_FAMILIES as $family) {
            $this->assertSame(
                [],
                $this->linesFor($issues, $family, $template),
                \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
            );
        }
    }

    /**
     * A Blade COMMENT is stripped before compilation, so `{{-- @props(...) --}}` emits no
     * `$attributes ??= new ComponentAttributeBag(...)` and the bag is never absent at runtime.
     * Classifying the view off the raw source read the commented directive as live and typed
     * `$attributes` nullable, turning the `merge()` call on the next line into a false
     * `PossiblyNullReference` on a perfectly valid component.
     */
    #[Test]
    public function a_commented_out_props_directive_does_not_make_attributes_nullable(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'components/commented-props.blade.php';

        $this->assertSame(
            [],
            $this->linesFor($issues, 'PossiblyNullReference', $template),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * The survivor family step 3 exists for: `nested-attributes.blade.php` is a `@props` component
     * view that itself renders a NESTED `<x-alert/>` tag — the shape whose generated open/close
     * bookkeeping re-checks `isset($attributes)`/`instanceof` after Laravel's own `??=` guard has
     * already made both provably true, which is exactly the population `ShadowIssueRelocator`'s new
     * drop targets (#1525).
     *
     * Asserts the WHOLE issue set on this template, not a hand-picked subset: `@props` replaces the
     * prelude's DOCBLOCK type with an INFERRED one (`compileProps()`'s
     * `$attributes = new ComponentAttributeBag($__newAttributes)`), so the nested tag's
     * `isset($attributes) && $attributes instanceof ...` guard lands in Reconciler's INFERRED branch
     * (`TypeDoesNotContainNull`/`TypeDoesNotContainType`), not the docblock branch
     * (`RedundantCondition`/`RedundantConditionGivenDocblockType`/`DocblockTypeContradiction`) —
     * both siblings are gated, or this test cannot see the exact shape it exists to pin.
     *
     * `PossiblyNullReference` on the `merge()` call used to be a survivor here too: the impossible
     * negative arm above left Psalm holding the reconciled `null` from the never-taken branch and
     * re-unioning it into `$attributes`'s type at `endif`. #1543 fixes it at the source instead of
     * gating the message: {@see \Psalm\LaravelPlugin\Blade\AttributesRestoreReassert} re-asserts
     * `$attributes` as non-null right after that `endif`, so the issue no longer fires at all.
     */
    #[Test]
    public function ambient_guards_around_a_nested_component_tag_are_dropped(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'components/nested-attributes.blade.php';

        $accounted = [...self::AMBIENT_GUARD_FAMILIES, 'PossiblyNullReference'];

        foreach ($accounted as $family) {
            $this->assertSame(
                [],
                $this->linesFor($issues, $family, $template),
                \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
            );
        }

        // Nothing else at all reports on this template.
        foreach ($issues as $issue) {
            if (\str_ends_with($issue['file_path'], $template)) {
                $this->assertContains(
                    $issue['type'],
                    $accounted,
                    "unaccounted issue on {$template}: " . \json_encode($issue, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
                );
            }
        }
    }

    /**
     * #1543: the same false `PossiblyNullReference` reported for `@aware` and a bare `$attributes`
     * mention, not only `@props` — the reconciler-branch shape differs (see the `@props` test above)
     * but the restore/strip `endif`s that leak `null` are identical regardless of which directive
     * declared the view a component.
     */
    #[Test]
    public function attributes_stays_non_null_after_a_nested_tag_in_an_aware_component_view(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'components/nested-attributes-aware.blade.php';

        $this->assertSame(
            [],
            $this->linesFor($issues, 'PossiblyNullReference', $template),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * #1557: `compileAware()`'s generated `foreach (['type' => 'info'] as $__key => $__value) {
     * $__consumeVariable = is_string($__key) ? ... }` iterates a literal array, so Psalm enumerates
     * the single pair and narrows `$__key` to its literal key — the `is_string($__key)` ternary
     * then reports `RedundantCondition` ("is always string") and its negated arm reports
     * `TypeDoesNotContainType` ("is always !string"), both against a variable the template author
     * never wrote and cannot act on. `nested-attributes-aware.blade.php` is the exact fixture that
     * reproduces the shape; the sibling test above already covers this template's
     * `PossiblyNullReference` survivor, so this checks the whole ambient-guard family instead of
     * only naming the two new classes, the same whole-set style
     * {@see ambient_guards_around_a_nested_component_tag_are_dropped()} uses for `@props`.
     */
    #[Test]
    public function ambient_key_guards_from_an_aware_directive_are_dropped(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'components/nested-attributes-aware.blade.php';

        // Guard against a vacuous pass: if `@aware()` stops compiling the literal-array
        // `$__key`/`$__value` loop (a compiler change, a fixture edit), the assertions below would
        // pass with nothing left to drop.
        $shadow = $this->shadowSourceFor($template);
        $this->assertStringContainsString(
            'as $__key => $__value',
            $shadow,
            "the fixture's @aware() directive never compiled the literal-array loop: no \$__key/\$__value foreach found in the compiled shadow",
        );

        // A loop alone isn't enough: `@aware([])` would compile the same `foreach` shape and still
        // report nothing, because Psalm can only enumerate per-pair KEYS out of a literal array with
        // an actual pair in it — that key enumerability, not the loop shape, is what makes the
        // reconciler fire in the first place.
        $this->assertStringContainsString(
            "'type' => 'info'",
            $shadow,
            "the fixture's @aware() directive compiled an empty array: no literal pair for Psalm to enumerate, so the reconciler this test exists to pin never fires",
        );

        foreach (self::AMBIENT_GUARD_FAMILIES as $family) {
            $this->assertSame(
                [],
                $this->linesFor($issues, $family, $template),
                \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
            );
        }
    }

    /**
     * #1694: `@session` compiles a conditional `$__sessionPrevious[] = $value` save and later reads
     * behind `isset()`, so Psalm reports the bookkeeping array as a possibly undefined global on
     * the directive line. The author's own conditionally assigned `$__authorLocal` on line 9 must
     * keep reporting: the prelude declares only the two save stacks (#1722).
     */
    #[Test]
    public function session_previous_value_stack_is_not_reported(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'session-previous.blade.php';

        $this->assertStringContainsString('$__sessionPrevious[] = $value', $this->shadowSourceFor($template));
        $this->assertSame(
            [9],
            $this->linesFor($issues, 'PossiblyUndefinedGlobalVariable', $template),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );

        // #1723: "first seen on line N" names the template line of the assignment (7), not the
        // shadow line Psalm counted.
        $this->assertSame(
            ['Possibly undefined global variable $__authorLocal, first seen on line 7'],
            $this->messagesFor($issues, 'PossiblyUndefinedGlobalVariable', $template),
        );
    }

    /** #1694: the `@context` twin of the `@session` stack above (`$__contextPrevious`). */
    #[Test]
    public function context_previous_value_stack_is_not_reported(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'context-previous.blade.php';

        $this->assertStringContainsString('$__contextPrevious[] = $value', $this->shadowSourceFor($template));
        $this->assertSame(
            [],
            $this->linesFor($issues, 'PossiblyUndefinedGlobalVariable', $template),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * #1722: the prelude declares the `@session`/`@context` save stacks `list<mixed>`, so neither
     * the `array_pop()` in `@endsession`/`@endcontext` nor the append in a nested or repeated block
     * sees a `mixed` stack. `MixedArgument` stays only on the author's own lines: the echo of the
     * mixed session value (line 2) and `count($__authorLocal)` (line 9).
     */
    #[Test]
    public function session_and_context_stacks_are_typed_under_report_mixed(): void
    {
        $issues = $this->analyze('psalm-blade-report-mixed.xml');
        $json = \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);

        $this->assertSame([2, 9], $this->linesFor($issues, 'MixedArgument', 'session-previous.blade.php'), $json);
        $this->assertSame([2], $this->linesFor($issues, 'MixedArgument', 'context-previous.blade.php'), $json);

        // `MixedAssignment` on `$value = session()->get()`/`context()->get()` is genuine; the
        // stack push and pop of that value are dropped (#1872).
        $stacks = \array_values(\array_filter($issues, static fn(array $issue): bool
            => \str_ends_with($issue['file_path'], 'session-context-stacks.blade.php') && $issue['type'] !== 'MixedAssignment'));
        $this->assertSame([], $stacks, $json);
    }

    /**
     * #1872: the compiled save/restore bookkeeping of a plain `<x-...>` tag (line 1), nested tags
     * (line 2), a multi-line tag (line 5), `@error` (line 8) and `@session`/`@context` (lines 9 to
     * 14) reports no `MixedAssignment`. Left: the `$value = session()->get()`/`context()->get()`
     * read on lines 9 and 12, which is not a save or restore.
     */
    #[Test]
    public function compiled_save_restore_bookkeeping_reports_no_mixed_assignment(): void
    {
        $issues = $this->analyze('psalm-blade-report-mixed.xml');
        $template = 'mixed-bookkeeping.blade.php';
        $json = \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);

        // Guard against a vacuous pass: every construct must have compiled its restore.
        $shadow = $this->shadowSourceFor('resources/views/' . $template, 'psalm-blade-report-mixed.xml');
        foreach (['$attributes = $__attributesOriginal', '$message = $__messageOriginal;', '$__sessionPrevious[] = $value;', '$value = array_pop($__contextPrevious);'] as $statement) {
            $this->assertStringContainsString($statement, $shadow);
        }

        $this->assertSame([9, 12], $this->linesFor($issues, 'MixedAssignment', $template), $json);
        $this->assertSame(
            ['Unable to determine the type that $value is being assigned to', 'Unable to determine the type that $value is being assigned to'],
            $this->messagesFor($issues, 'MixedAssignment', $template),
            $json,
        );
    }

    /**
     * #1872 negative: an author's own `$attributes` assignment (line 1) does not have the compiled
     * shape, and an author who writes the compiled restore verbatim (line 2) keeps the finding. The
     * tag's own restore (line 3) differs from line 2 by its hash and is still dropped.
     */
    #[Test]
    public function an_author_written_save_restore_look_alike_keeps_reporting(): void
    {
        $issues = $this->analyze('psalm-blade-report-mixed.xml');

        $this->assertSame(
            [1, 2],
            $this->linesFor($issues, 'MixedAssignment', 'mixed-bookkeeping-authored.blade.php'),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * #1722: nested and repeated `@session`/`@context` blocks report nothing under the default
     * config, with the stacks declared in the prelude rather than gated by name.
     */
    #[Test]
    public function nested_and_repeated_session_and_context_blocks_report_nothing(): void
    {
        $issues = $this->analyze('psalm.xml');

        $stacks = \array_values(\array_filter($issues, static fn(array $issue): bool
            => \str_ends_with($issue['file_path'], 'session-context-stacks.blade.php')));
        $this->assertSame([], $stacks, \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
    }

    /**
     * #1724: `@session`/`@context` save an outer `$value` behind a compiled `isset($value)`, which
     * Psalm judged against the author's own `$value`: a foreach value (line 2), `null` (line 5,
     * the `@context` twin) and a literal (line 8). The author's own `@if (isset($value))` on line
     * 7 keeps reporting.
     */
    #[Test]
    public function the_compiled_session_value_save_is_not_judged_against_an_author_value(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'session-value-save.blade.php';
        $json = \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('if (isset($value)) { $__contextPrevious[] = $value; }', $this->shadowSourceFor($template));
        $this->assertSame([7], $this->linesFor($issues, 'RedundantCondition', $template), $json);
        $this->assertSame([], $this->linesFor($issues, 'TypeDoesNotContainType', $template), $json);
    }

    /**
     * #1724: the drop needs the save statement to be absent from the template, so an author who
     * writes it verbatim (line 2) keeps the finding, and so does every compiled save in that
     * template (line 3).
     */
    #[Test]
    public function an_author_written_value_save_keeps_reporting(): void
    {
        $issues = $this->analyze('psalm.xml');

        $this->assertSame(
            [2, 3],
            $this->linesFor($issues, 'RedundantCondition', 'session-value-save-authored.blade.php'),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * #1810: a `Collection|array` `@foreach`/`@forelse` subject makes Psalm resolve the iterator
     * through a synthetic `$__currentLoopData->getIterator()` call that it suppresses in plain PHP
     * (ForeachAnalyzer.php), positioned on the variable: `PossiblyInvalidMethodCall` for the list
     * branch (line 8), `PossiblyUndefinedMethod` for the `ArrayIterator` branch (line 3). Line 11 is
     * a real call on the same variable, positioned on the method name, and keeps reporting.
     */
    #[Test]
    public function foreach_over_a_union_subject_does_not_report_the_synthetic_iterator_call(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'foreach-union-subject.blade.php';
        $json = \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);

        $this->assertSame([11], $this->linesFor($issues, 'PossiblyInvalidMethodCall', $template), $json);
        $this->assertSame([], $this->linesFor($issues, 'PossiblyUndefinedMethod', $template), $json);
    }

    /**
     * #1696: `$loop->parent` stays nullable in the ambient loop shape, so a nested loop's
     * `$loop->parent->iteration` (line 4) and `$loop->parent->parent->iteration` (line 6, one
     * finding per level) reported PossiblyNullPropertyFetch. An author's own nullable `->parent`
     * on another variable (line 11) keeps reporting: the match is anchored to `$loop`.
     */
    #[Test]
    public function loop_parent_is_not_reported_as_possibly_null(): void
    {
        $issues = $this->analyze('psalm.xml');

        $this->assertSame(
            ['Cannot get property on possibly null variable $node->parent of type null|object{iteration:int}'],
            $this->messagesFor($issues, 'PossiblyNullPropertyFetch', 'loop-parent.blade.php'),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * A view variable declared in a PHPDoc `@var` is required unless the declaration carries
     * `= null`, so a `??`, `??=` or `isset()` guard on one is redundant and reports like in PHP
     * (lines 5-7). An optional variable is declared `T|null $x = null` and its guards report
     * nothing (lines 8-10). #1697 once dropped these findings.
     */
    #[Test]
    public function a_guard_on_a_required_view_variable_reports(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'required-guard.blade.php';
        $json = \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);
        $expected = [
            'RedundantCondition' => [],
            'RedundantConditionGivenDocblockType' => [5, 6, 7],
            'DocblockTypeContradiction' => [5, 6],
            'TypeDoesNotContainNull' => [],
            'TypeDoesNotContainType' => [],
        ];

        foreach ($expected as $family => $lines) {
            $this->assertSame($lines, $this->linesFor($issues, $family, $template), "{$family}: {$json}");
        }
    }

    /**
     * #1871: a named slot declared non-null in a component view keeps the required-variable
     * semantics above, and its `isset()` (line 10), `??` (13) and `??=` (14) guards name the
     * optional declaration. Not hinted: `$slot` (15, dropped as an ambient guard since #1525), a
     * non-slot type (16), a plain page, and the optional declaration: its first guards report
     * nothing, and the ones nested inside an `isset()` (lines 8-10) are redundant through narrowing,
     * which the hint would misread as the declaration's fault.
     */
    #[Test]
    public function a_guard_on_a_required_named_slot_reports_with_the_optional_hint(): void
    {
        $issues = $this->analyze('psalm.xml');
        $json = \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);
        $hint = static fn(string $name): string
            => " (named slots are optional: declare `\\Illuminate\\View\\ComponentSlot|null \${$name} = null`)";

        $template = 'components/named-slot-guard.blade.php';
        $this->assertSame([10, 13, 14, 16], $this->linesFor($issues, 'RedundantConditionGivenDocblockType', $template), $json);
        $this->assertSame([
            'Docblock-defined type Illuminate\View\ComponentSlot for $footer is never null' . $hint('footer'),
            'Docblock-defined type Illuminate\View\ComponentSlot for $header is never null' . $hint('header'),
            'Docblock-defined type Illuminate\View\ComponentSlot for $header is never null' . $hint('header'),
            'Docblock-defined type string for $title is never null',
        ], $this->messagesFor($issues, 'RedundantConditionGivenDocblockType', $template), $json);
        $this->assertSame([13, 14], $this->linesFor($issues, 'DocblockTypeContradiction', $template), $json);
        $this->assertSame(
            \array_fill(0, 2, 'Cannot resolve types for $header - docblock-defined type Illuminate\View\ComponentSlot does not contain null' . $hint('header')),
            $this->messagesFor($issues, 'DocblockTypeContradiction', $template),
            $json,
        );

        $template = 'components/named-slot-optional.blade.php';
        $expected = [
            'RedundantCondition' => [],
            'RedundantConditionGivenDocblockType' => [8, 9, 10],
            'DocblockTypeContradiction' => [9, 10],
            'TypeDoesNotContainNull' => [],
            'TypeDoesNotContainType' => [],
        ];

        foreach ($expected as $family => $lines) {
            $this->assertSame($lines, $this->linesFor($issues, $family, $template), "{$family}: {$json}");
        }

        $this->assertNoNamedSlotHint($issues, $template);

        $template = 'resources/views/named-slot-plain.blade.php';
        $this->assertSame([2, 3], $this->linesFor($issues, 'RedundantConditionGivenDocblockType', $template), $json);
        $this->assertSame([3], $this->linesFor($issues, 'DocblockTypeContradiction', $template), $json);
        $this->assertNoNamedSlotHint($issues, $template);
    }

    /** @param list<array{type: string, file_path: string, line_from: int, message: string}> $issues */
    private function assertNoNamedSlotHint(array $issues, string $template): void
    {
        foreach (['RedundantConditionGivenDocblockType', 'DocblockTypeContradiction'] as $family) {
            foreach ($this->messagesFor($issues, $family, $template) as $message) {
                $this->assertStringNotContainsString('named slots', $message, $template);
            }
        }
    }

    /**
     * #1695: `compileAware()`'s generated loop calls `getConsumableComponentData($__value)` in the
     * list-form arm, which only runs for an int key at runtime. Psalm does not correlate the two
     * `is_string($__key)` ternaries, so every keyed non-string default reaches that arm: `null`
     * (line 2) and a nullable one (line 7) here, `false`/`0`/`true`/`[]` and mixed lists in
     * `aware-scalar-default`. The author's own calls with literal arguments keep reporting.
     */
    #[Test]
    public function non_string_aware_defaults_do_not_report_against_the_generated_list_form_call(): void
    {
        $issues = $this->analyze('psalm.xml');
        $json = \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);

        // Guard against a vacuous pass: the list-form arm must still be compiled.
        $this->assertStringContainsString('$__env->getConsumableComponentData($__value', $this->shadowSourceFor('components/aware-scalar-default.blade.php'));

        $template = 'components/aware-null-default.blade.php';
        $this->assertSame([5], $this->linesFor($issues, 'NullArgument', $template), $json);
        $this->assertSame([], $this->linesFor($issues, 'PossiblyNullArgument', $template), $json);

        $template = 'components/aware-scalar-default.blade.php';
        $this->assertSame([7, 8], $this->linesFor($issues, 'InvalidArgument', $template), $json);
        $this->assertSame([8], $this->linesFor($issues, 'InvalidCast', $template), $json);
        $this->assertSame([], $this->linesFor($issues, 'PossiblyFalseArgument', $template), $json);
    }

    /**
     * KnownLimitation (#1725): the #1695 gate keys on the generated call text, not on the list
     * item, so a non-string list item (`@aware([null])`, `@aware([$maybe])` with a nullable
     * `$maybe`, a bare `null` next to a keyed pair) is dropped together with the artifact it is
     * indistinguishable from. At runtime each is a bug (a null key); here none reports. Pins the
     * silence; the caveat is on {@see ShadowIssueRelocator::isGeneratedAwareArgument()}.
     */
    #[Test]
    public function a_non_string_aware_list_item_stays_silent_known_limitation(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'components/aware-non-string-item-known-limitation.blade.php';
        $json = \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);

        // Guard against a vacuous pass: the list-form arm the gate drops must still be compiled.
        $this->assertStringContainsString('$__env->getConsumableComponentData($__value', $this->shadowSourceFor($template));

        foreach (['NullArgument', 'PossiblyNullArgument', 'InvalidArgument', 'NoValue'] as $family) {
            $this->assertSame([], $this->linesFor($issues, $family, $template), $json);
        }
    }

    /**
     * #1695: the gate declines when the template itself contains the generated call text, so an
     * author-written `getConsumableComponentData($__value)` with a null `$__value` keeps reporting.
     */
    #[Test]
    public function an_author_written_list_form_call_keeps_reporting(): void
    {
        $issues = $this->analyze('psalm.xml');

        $this->assertContains(
            2,
            $this->linesFor($issues, 'NullArgument', 'components/aware-author-list-call.blade.php'),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * The keyed-arm mirror of #1695: an all-int-key list (`@aware(['color'])`, lines 1 and 2)
     * narrows `$__key` to `never` inside `is_string($__key) ? ...getConsumableComponentData($__key,
     * ...)`, which Psalm reports as `NoValue`. Mixed lists (lines 3 and 4) keep a string key and
     * never reported.
     */
    #[Test]
    public function list_form_aware_does_not_report_no_value_against_the_generated_keyed_call(): void
    {
        $issues = $this->analyze('psalm.xml');

        // Guard against a vacuous pass: the keyed arm must still be compiled.
        $this->assertStringContainsString('$__env->getConsumableComponentData($__key, $__value)', $this->shadowSourceFor('components/aware-list-form.blade.php'));

        $this->assertSame(
            [],
            \array_values(\array_filter($issues, static fn(array $issue): bool => \str_ends_with($issue['file_path'], 'components/aware-list-form.blade.php'))),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * A marker between the attribute lines of a multi-line `<x-...>` tag leaves the opening tag
     * uncompiled while `</x-alert>` still compiles, and its orphan `endif` is a `ParseError`. The
     * `->`/`=>` inside the tag's `{{ }}` and `@class()` attributes must not end the skip early.
     */
    #[Test]
    public function a_multiline_component_tag_compiles_without_a_parse_error(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'components/multiline-tag.blade.php';

        $this->assertSame([], $this->linesFor($issues, 'ParseError', $template), \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));

        // The opening tag compiled rather than surviving as literal text.
        $shadow = $this->shadowSourceFor($template);
        $this->assertStringContainsString('->renderComponent()', $shadow);
        $this->assertStringNotContainsString('<x-alert', $shadow);
    }

    /**
     * A precompiler owning its own tag prefix (`<native:...>`, `<flux:...>`) matches attributes as
     * strictly as ComponentTagCompiler. A marker inside a multi-line `<foo:alert>` left the opener
     * uncompiled, and the closer's orphan `endif` lost the template to a `ParseError` (#1910).
     */
    #[Test]
    public function a_multiline_prefixed_precompiled_tag_compiles_without_a_parse_error(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'resources/views/prefixed-precompiled-tag.blade.php';
        $json = \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);

        $this->assertSame([], $this->linesFor($issues, 'ParseError', $template), $json);
        $this->assertSame([13], $this->linesFor($issues, self::ISSUE, $template), $json);

        // Both openers compiled rather than surviving as literal text.
        $shadow = $this->shadowSourceFor($template);
        $this->assertSame(2, \substr_count($shadow, '->renderComponent()'));
        $this->assertStringNotContainsString('<x-alert', $shadow);
    }

    /** The keyed-arm gate declines when the template itself contains the generated call text. */
    #[Test]
    public function an_author_written_keyed_call_keeps_reporting_no_value(): void
    {
        $issues = $this->analyze('psalm.xml');

        $this->assertContains(
            2,
            $this->linesFor($issues, 'NoValue', 'components/aware-author-keyed-call.blade.php'),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function attributes_stays_non_null_after_a_nested_tag_in_a_bare_mention_component_view(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'components/nested-attributes-mention.blade.php';

        $this->assertSame(
            [],
            $this->linesFor($issues, 'PossiblyNullReference', $template),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * #1543 trap 3: a read INSIDE the nested tag's own body degrades at the inner strip's `endif`,
     * which precedes the restore — fixing only the restore leaves this case red.
     */
    #[Test]
    public function attributes_stays_non_null_for_a_read_inside_the_nested_tags_body(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'components/nested-attributes-inside-body.blade.php';

        $this->assertSame(
            [],
            $this->linesFor($issues, 'PossiblyNullReference', $template),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * #1543 negative: the reassert is scoped to `$attributes` by name. An author's own nullable
     * local that merely happens to share a nested `<x-...>` tag's template must keep reporting.
     */
    #[Test]
    public function an_authors_own_nullable_local_still_reports_after_a_nested_tag(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'components/nested-attributes-usernull.blade.php';

        $this->assertSame(
            [7],
            $this->linesFor($issues, 'PossiblyNullReference', $template),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * #1543 external review finding 2: the restore/strip `endif`s only prove LARAVEL'S OWN
     * bookkeeping left `$attributes` non-null between the `<x-...>` tag's save and restore — never
     * that an author's own reassignment inside the same view, between `@props` and the tag, didn't
     * null it out again first. Laravel skips the save/strip/restore entirely on this path (the save
     * is `isset($attributes)`-gated, and this reassignment runs AFTER it), so `$attributes` genuinely
     * stays null at the read on line 4. The re-assert must not paper over it.
     */
    #[Test]
    public function an_authors_own_reassignment_of_attributes_still_reports_after_a_nested_tag(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'components/nested-attributes-authornull.blade.php';

        $this->assertSame(
            [4],
            $this->linesFor($issues, 'PossiblyNullReference', $template),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * #1525 acceptance (d), the negative case for the message gate: an author's own redundant
     * check against their own docblock must still report, even though it shares a class with the
     * dropped ambient-guard families. Deliberately outside `components/` and never mentions
     * `$attributes`/`$slot`, so the classifier declares nothing for this template either way.
     */
    #[Test]
    public function an_authors_own_docblock_contradiction_still_reports(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'resources/views/author-contradiction.blade.php';

        $matching = [];

        foreach (self::AMBIENT_GUARD_FAMILIES as $family) {
            foreach ($this->linesFor($issues, $family, $template) as $line) {
                $matching[] = $line;
            }
        }

        $this->assertCount(1, $matching, \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
    }

    /**
     * #1532: `$component` is never given a type by
     * {@see \Psalm\LaravelPlugin\Blade\PreludeBuilder::componentTypesFor()} in ANY template (the
     * prelude declares it `mixed` or, from tag bookkeeping, a nullable `Component`), unlike
     * `$attributes`/`$slot`, whose docblock-vs-inferred split only exists inside a component view.
     * `nested-component-tags.blade.php` is a plain page (no `@props`/`@aware`/`$attributes`/
     * `$slot`), so `isComponentView` is false, yet it nests one `<x-alert>` tag inside another's
     * slot: the OUTER tag's compiled `resolve()` call narrows `$component` to a concrete class
     * before its own restore runs, and the INNER tag's opening save guard re-checks `isset($component)`
     * while that narrowed type is still live, making the check provably redundant regardless of
     * `isComponentView`. The unrelated `$range` guard on the same template is the author's own
     * docblock contradiction and must survive: the fix is message-specific, not a blanket per-file
     * suppression of the family.
     */
    #[Test]
    public function ambient_component_guard_is_dropped_for_a_non_component_caller_with_nested_tags(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'resources/views/nested-component-tags.blade.php';

        // Guard against a vacuous pass: the test must fail if the INNER `<x-alert>` tag ever stops
        // compiling (e.g. a fixture edit collapses the nesting), not just pass because there is
        // nothing left to drop. Each `<x-...>` tag compiles its own opening `isset($component)` save
        // guard, so two tags means two.
        $this->assertSame(
            2,
            \substr_count($this->shadowSourceFor($template), 'if (isset($component)) {'),
            "expected two compiled component save guards (one per <x-alert> tag); the nesting this test exists to pin did not survive compilation:\n" . $this->shadowSourceFor($template),
        );

        $componentGuards = \array_values(\array_filter(
            $issues,
            static fn(array $issue): bool => \str_ends_with($issue['file_path'], $template)
                && \in_array($issue['type'], self::AMBIENT_GUARD_FAMILIES, true)
                && \str_contains($issue['message'], ' for $component'),
        ));

        $this->assertSame([], $componentGuards, \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));

        $authorGuards = \array_filter(
            $issues,
            static fn(array $issue): bool => \str_ends_with($issue['file_path'], $template)
                && \in_array($issue['type'], self::AMBIENT_GUARD_FAMILIES, true)
                && \str_contains($issue['message'], ' for $range'),
        );

        $this->assertCount(1, $authorGuards, \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
    }

    /**
     * #1525 §0.1: the `@component('view', [...])` directive path hands `$slot` a ComponentSlot
     * too (ManagesComponents::componentData() builds the same default slot either way), so it
     * needs no union and no weakening — `$slot->isEmpty()` must type-check exactly as it does on
     * the `<x-*>` path. Run under `psalm-blade-report-mixed.xml`: see the docblock on
     * attributes_merge_and_slot_stay_typed_in_a_props_component_view() for why the default config
     * makes the `MixedMethodCall` half of this assertion vacuous.
     */
    #[Test]
    public function slot_is_typed_the_same_way_on_the_component_directive_path(): void
    {
        $issues = $this->analyze('psalm-blade-report-mixed.xml');
        $template = 'resources/views/classic-slot.blade.php';

        foreach (['UndefinedMethod', 'MixedMethodCall', 'PossiblyUndefinedMethod'] as $family) {
            $this->assertSame(
                [],
                $this->linesFor($issues, $family, $template),
                \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
            );
        }
    }

    /**
     * #1701: `$component` is Blade's own local inside a template that writes a `<x-...>` tag, and
     * a class-component tag types it as the tag's class for the length of its slot
     * (`$component = Chip::resolve(...)`). A method the class has stays silent; one it lacks
     * reports an UndefinedMethod naming the class, which only happens if the slot sees the class
     * type rather than `mixed`.
     */
    #[Test]
    public function component_is_typed_as_the_tags_class_inside_its_slot(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'resources/views/class-component-slot.blade.php';

        $this->assertSame(
            [3],
            $this->linesFor($issues, 'UndefinedMethod', $template),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );

        $messages = $this->messagesFor($issues, 'UndefinedMethod', $template);
        $this->assertStringContainsString('App\\View\\Components\\Chip::missingMethod', $messages[0]);
        $this->assertStringNotContainsString('::label', $messages[0]);
    }

    /**
     * #1553: an empty bound component attribute (`:value=""`) compiles to a dangling `'value' =>`
     * in the component's data array (here `'value' => ]`; with a sibling attribute, `'value' => ,`
     * as in the original report), a syntax error mid-shadow. Before the fix,
     * PreludeBuilder::undeclaredVariables() caught the resulting
     * PhpParser\Error and dropped the ENTIRE `@var mixed` net for the template, so every OTHER
     * variable Blade never declares (here, `$undeclared` bound to the second tag) surfaced as a
     * false UndefinedGlobalVariable instead of the genuine syntax error.
     */
    #[Test]
    public function a_bound_attribute_syntax_error_does_not_drop_the_prelude_for_other_reads(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'resources/views/bound-attr-syntax-error.blade.php';

        $this->assertSame(
            [],
            $this->linesFor($issues, 'UndefinedGlobalVariable', $template),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );

        // Guard against a vacuous pass: the empty bound attribute must actually have compiled to
        // the erroring shape this test exists to pin (a missing expression before `]` in the
        // component's data array), not silently changed under a Laravel bump.
        $this->assertStringContainsString(
            "'value' => ]",
            $this->shadowSourceFor($template),
            "expected the empty bound attribute to compile to a syntax error; got:\n" . $this->shadowSourceFor($template),
        );

        // The genuine syntax error keeps reporting (the issue's own acceptance): only the false
        // UndefinedGlobalVariable findings go away, never the ParseError.
        $this->assertNotSame([], $this->linesFor($issues, 'ParseError', $template));
    }

    /**
     * #1558: a `__`-prefixed name a host app shares as a global (e.g. bookstack's
     * `$__themeViews`) is READ by the template but never assigned anywhere in its compiled
     * output, so the design default (undeclared => silent `mixed`) must apply exactly like any
     * other unrecognized name — before the fix, `undeclaredVariables()`'s own `__`-prefix skip
     * dropped it from the mixed-declaration pass, leaving the read genuinely undeclared.
     */
    #[Test]
    public function a_shared_underscore_prefixed_global_read_reports_no_undefined_global_variable(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'resources/views/shared-underscore-global.blade.php';

        $this->assertSame(
            [],
            $this->linesFor($issues, 'UndefinedGlobalVariable', $template),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );

        // Guard against a vacuous pass: the read must have actually reached the prelude pass, and
        // the component tag's own compiled bookkeeping (unrelated `$__`-prefixed names) must still
        // be present, unchanged by widening the mixed-declaration pass to the whole `__` family.
        $shadow = $this->shadowSourceFor($template);
        $this->assertStringContainsString('@var mixed $__themeViews', $shadow);
        $this->assertStringContainsString('$__componentOriginal', $shadow);
    }

    /**
     * A template-assigned local is not view data: declaring it `@var mixed` ahead of its own
     * assignment makes Psalm drop the `$hasAction` <-> `$action !== null` correlation after the
     * next `if`, so the guarded `$action->attributes` read reported PossiblyNullPropertyFetch.
     */
    #[Test]
    public function a_template_assigned_flag_keeps_guarding_the_nullable_it_was_derived_from(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'resources/views/assigned-flag-guard.blade.php';

        $this->assertStringNotContainsString('$hasAction */', $this->shadowSourceFor($template));
        $this->assertSame(
            [],
            \array_values(\array_filter($issues, static fn(array $issue): bool => \str_ends_with($issue['file_path'], $template))),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /** An assignment that reads its own target needs the incoming value declared. */
    #[Test]
    public function a_self_reading_assignment_keeps_its_mixed_declaration(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'resources/views/self-reassigned.blade.php';

        $this->assertStringContainsString('@var mixed $title */', $this->shadowSourceFor($template));
        $this->assertSame([], $this->linesFor($issues, 'UndefinedGlobalVariable', $template));
        $this->assertSame([], $this->linesFor($issues, 'UndefinedVariable', $template));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2?: string}>
     */
    public static function branchAssignedLocals(): iterable
    {
        yield '@if' => ['locals/if.blade.php', 'label'];
        // Declared from its literal default (#1828) rather than `mixed`, but declared all the same.
        yield '@props default overridden in a branch' => ['locals/props.blade.php', 'size', 'string'];
        yield '@once' => ['locals/once.blade.php', 'label'];
        yield '<x-…> slot' => ['locals/slot.blade.php', 'label'];
    }

    /**
     * An assignment that can be skipped (a branch, `@once`, a component slot) leaves the incoming
     * view value as the one read after it, a `@props` default or a parent's variable in a partial,
     * so the name keeps its prelude declaration and the read is not possibly undefined.
     */
    #[Test]
    #[DataProvider('branchAssignedLocals')]
    public function a_local_assigned_in_a_skippable_block_stays_declared(string $template, string $name, string $type = 'mixed'): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'resources/views/' . $template;

        $this->assertStringContainsString("@var {$type} \${$name} */", $this->shadowSourceFor($template));
        $this->assertSame(
            [],
            $this->linesFor($issues, 'PossiblyUndefinedGlobalVariable', $template),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function qualifiedDocblocks(): iterable
    {
        yield 'leading backslash' => ['contract-typed-body.blade.php'];
        // What `blade:annotate` writes: resolves identically in the namespace-less shadow.
        yield 'no leading backslash' => ['contract-unqualified.blade.php'];
    }

    /** A qualified raw `@var` docblock types the template body. */
    #[Test]
    #[DataProvider('qualifiedDocblocks')]
    public function a_raw_var_docblock_types_the_template_body(string $template): void
    {
        $issues = $this->analyze('psalm.xml');

        $this->assertSame(
            [2],
            $this->linesFor($issues, 'UndefinedMethod', 'resources/views/' . $template),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /** A raw docblock naming a vendor class nothing else in the project mentions still types the body. */
    #[Test]
    public function a_raw_var_docblock_naming_an_otherwise_unreferenced_vendor_class_types_the_body(): void
    {
        $issues = $this->analyze('psalm.xml');

        $this->assertSame(
            [2],
            $this->linesFor($issues, 'UndefinedMagicMethod', 'resources/views/contract-vendor-class.blade.php'),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function propsDefaultTypes(): iterable
    {
        yield 'string' => ['literals.blade.php', '$size: string'];
        yield 'int' => ['literals.blade.php', '$count: int'];
        yield 'float' => ['literals.blade.php', '$ratio: float'];
        yield 'false' => ['literals.blade.php', '$open: bool'];
        yield 'upper-case TRUE' => ['literals.blade.php', '$shown: bool'];
        yield 'empty short array' => ['literals.blade.php', '$items: array<array-key, mixed>'];
        yield 'long array() with entries' => ['literals.blade.php', '$pairs: array<array-key, mixed>'];
        yield 'negative int' => ['literals.blade.php', '$offset: int'];
        yield 'negative float' => ['literals.blade.php', '$scale: float'];
        yield 'bare name' => ['untyped.blade.php', '$title: mixed'];
        yield 'null' => ['untyped.blade.php', '$none: mixed'];
        yield 'constant' => ['untyped.blade.php', '$eol: mixed'];
        yield 'call' => ['untyped.blade.php', '$upper: mixed'];
        yield 'concatenation' => ['untyped.blade.php', '$joined: mixed'];
        yield 'unary plus' => ['untyped.blade.php', '$plus: mixed'];
        yield 'negated constant' => ['untyped.blade.php', '$max: mixed'];
        yield 'variable' => ['untyped.blade.php', '$copied: mixed'];
        yield 'interpolated string' => ['untyped.blade.php', '$line: mixed'];
        // An explicit declaration beats the inferred default: a native docblock re-declares the
        // name after the prelude, whether it sits before or after `@props` in the template.
        yield 'native @var before @props' => ['declared-native.blade.php', '$size: int'];
        yield 'native @var after @props' => ['declared-native-after.blade.php', '$size: int'];
        // `@aware` assigns unconditionally (no `??`), so a name it shares with `@props` holds the
        // parent's value, whichever directive comes first.
        yield '@aware after @props' => ['aware-after.blade.php', '$color: mixed'];
        yield '@aware before @props' => ['aware-before.blade.php', '$color: mixed'];
        yield '@props name @aware does not share' => ['aware-before.blade.php', '$size: string'];
        // An unreadable list may set any name first, and a later default never replaces it (`??`).
        yield 'after an unreadable @props' => ['unreadable-first.blade.php', '$size: mixed'];
    }

    /** #1828: a `@props` entry types the body from its literal default, widened, never the literal itself. */
    #[Test]
    #[DataProvider('propsDefaultTypes')]
    public function a_props_entry_is_typed_from_its_literal_default(string $template, string $trace): void
    {
        $issues = $this->analyze('psalm.xml');

        $this->assertContains(
            $trace,
            $this->messagesFor($issues, 'Trace', 'resources/views/props/' . $template),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * The mistakes a typed default exposes, all silent while the prop was `mixed`. The guard on
     * line 5 is the documented cost of a default-only claim: a caller could pass an array.
     */
    #[Test]
    public function misusing_a_literal_defaulted_prop_reports(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'resources/views/props/misuse.blade.php';
        $reported = [];

        foreach ($issues as $issue) {
            if (\str_ends_with($issue['file_path'], $template)) {
                $reported[] = $issue['line_from'] . ' ' . $issue['type'];
            }
        }

        $this->assertSame(['2 InvalidPropertyFetch', '3 InvalidMethodCall', '4 InvalidPropertyFetch', '5 DocblockTypeContradiction'], $reported);
    }

    /** Blade echoes a bool as `'1'` / `''`, so a `bool` default is no `e()` argument error. */
    #[Test]
    public function a_bool_default_echoes_silently(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'resources/views/props/bool-echo.blade.php';

        $this->assertStringContainsString('@var bool $open */', $this->shadowSourceFor($template));
        $this->assertSame(
            [],
            \array_values(\array_filter($issues, static fn(array $issue): bool => \str_ends_with($issue['file_path'], $template))),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /** Widened, not `'lg'`: a `match` or comparison against another value is not a dead branch. */
    #[Test]
    public function a_string_default_leaves_other_values_reachable(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'resources/views/props/match.blade.php';

        $this->assertStringContainsString('@var string $size */', $this->shadowSourceFor($template));
        $this->assertSame(
            [],
            \array_values(\array_filter($issues, static fn(array $issue): bool => \str_ends_with($issue['file_path'], $template))),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * #1554: a bound attribute followed by ANOTHER attribute on the same tag compiles to
     * `'value' => ,'label' => ...` (the `'value' => ]` shape #1553 pinned is the OTHER position: an
     * empty bound attribute with nothing after it on that tag). Both are genuine `ParseError`s
     * Laravel's own `ComponentTagCompiler::attributesToString()` would emit at render time; this
     * test pins that the malformed line still gets attributed back to a template line, and to the
     * RIGHT one.
     *
     * The multi-attribute component tag is one `MarkerPrePass::computeSkipLines()` match end to end
     * (the guard at src/Blade/MarkerPrePass.php:112-116 is MANDATORY: a marker between two attributes
     * would break `compileOpeningTags()`'s strict attribute alternation), so every shadow line the
     * tag's PHP expands to maps back to the tag's OPENING line, not to wherever `:value` sits inside
     * it — there is no more granular line to attribute the syntax error to.
     */
    #[Test]
    public function a_bound_attribute_syntax_error_before_another_attribute_maps_to_the_tag_line(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'resources/views/bound-attr-empty-middle.blade.php';

        // The dangling comma is ambiguous enough that PhpParser reports several cascading
        // ParseErrors off the one malformed expression; every one of them must still land on the
        // tag's opening line (line 2 — a filler line 1 keeps this off the unmapped fallback's own
        // line 1, which would otherwise make the assertion pass vacuously), never on an unmapped or
        // wrong line.
        $lines = $this->linesFor($issues, 'ParseError', $template);
        $this->assertNotSame([], $lines, \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
        $this->assertSame([2], \array_values(\array_unique($lines)), \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));

        foreach ($issues as $issue) {
            if ($issue['type'] === 'ParseError' && \str_ends_with($issue['file_path'], $template)) {
                $this->assertStringNotContainsString(
                    '(unmapped)',
                    $issue['message'],
                    \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
                );
            }
        }

        // Guard against a vacuous pass: pin the actual malformed shape (missing expression before
        // the comma), not merely that SOME ParseError fired.
        $this->assertStringContainsString(
            "'value' => ,",
            $this->shadowSourceFor($template),
            "expected the empty bound attribute to compile to a dangling comma; got:\n" . $this->shadowSourceFor($template),
        );

        // Neighbour isolation: a finding on an unrelated template in the same run must survive
        // untouched. This issue never had a neighbour assertion in this suite.
        $this->assertSame(
            [11],
            $this->linesFor($issues, 'InvalidReturnStatement', 'resources/views/docblock-suppression.blade.php'),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * #1554: a self-closing component tag (`<x-form.label for="a" />`) followed by a stray closing
     * tag for the same component. `ComponentTagCompiler::compileClosingTags()` has no stack check, so
     * it emits the `renderComponent()`/`endif` epilogue for a component that was never opened,
     * producing an orphan `@endif` with no matching `@if` — a genuine `ParseError` (`T_ENDIF`), not a
     * v-slot or attribute artifact. The three-line repro is minimal: the leading self-closing tag,
     * the closing tag, and nothing else, is enough to reproduce it.
     */
    #[Test]
    public function a_stray_closing_tag_over_a_self_closed_component_maps_to_its_own_line(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'resources/views/stray-closing-tag.blade.php';

        $this->assertSame(
            [3],
            $this->linesFor($issues, 'ParseError', $template),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );

        // #1723: php-parser's trailing " on line N" names the template line too.
        $this->assertSame(
            ['Syntax error, unexpected T_ENDIF on line 3'],
            $this->messagesFor($issues, 'ParseError', $template),
        );

        // Guard against a vacuous pass: a well-formed `<x-alert />` alone already emits several
        // `endif;` lines as part of its own save/restore bookkeeping, so a bare substring check for
        // "endif" cannot fail. `renderComponent()` is only ever emitted once per genuinely opened
        // component; a second occurrence is the stray closing tag's own orphaned epilogue.
        $this->assertSame(
            2,
            \substr_count($this->shadowSourceFor($template), 'renderComponent()'),
            "expected the stray closing tag to emit a second, orphaned renderComponent() call; got:\n" . $this->shadowSourceFor($template),
        );

        // Neighbour isolation: a finding on an unrelated template in the same run must survive
        // untouched. This issue never had a neighbour assertion in this suite.
        $this->assertSame(
            [2],
            $this->linesFor($issues, 'UnusedForeachValue', 'resources/views/foreach-unused-value.blade.php'),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * #1723: the message of `ReferenceReusedFromConfusingScope` embeds a `file:line:column`
     * descriptor of the reference's definition (template line 4), which must not name the shadow.
     */
    #[Test]
    public function a_location_descriptor_inside_a_message_names_the_template(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'resources/views/reference-reused.blade.php';

        $this->assertSame(
            [8],
            $this->linesFor($issues, 'ReferenceReusedFromConfusingScope', $template),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );

        $messages = $this->messagesFor($issues, 'ReferenceReusedFromConfusingScope', $template);
        $this->assertCount(1, $messages);
        $this->assertMatchesRegularExpression('#^\$row is possibly a reference defined at \S*reference-reused\.blade\.php:4:\d+\. #', $messages[0]);
    }

    /**
     * #1801: Psalm analyzes a `match` as a desugared ternary and drops two families of findings
     * on plain PHP: the positionless `TypeDoesNotContainType` of the synthesized `UnhandledMatchError`
     * branch, and `RedundantCondition*` inside the arms (MatchAnalyzer suppresses them at runtime,
     * which `BeforeAddIssue` does not carry). The same condition outside the match, and the
     * unsuppressed sibling classes, must keep reporting.
     */
    #[Test]
    public function a_match_reports_only_what_psalm_reports_on_plain_php(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'resources/views/match-bool.blade.php';
        $context = \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);

        $this->assertSame([], $this->linesFor($issues, 'TypeDoesNotContainType', $template), $context);
        $this->assertSame([], $this->linesFor($issues, 'RedundantConditionGivenDocblockType', $template), $context);
        // Line 19 is an author `elseif` outside the match with byte-identical wording to the arm finding.
        $this->assertSame([19], $this->linesFor($issues, 'RedundantCondition', $template), $context);
        $this->assertSame([13], $this->linesFor($issues, 'DocblockTypeContradiction', $template), $context);
        $this->assertSame([14], $this->linesFor($issues, 'ParadoxicalCondition', $template), $context);
    }

    /**
     * #1801 negative: MatchAnalyzer only suppresses `RedundantCondition*` over the arms. The subject
     * and a `match` used as a condition operand (the finding sits on the whole expression) are
     * outside every arm and report on plain PHP, so they must keep reporting in a template.
     */
    #[Test]
    public function a_match_keeps_the_redundant_conditions_psalm_reports_outside_its_arms(): void
    {
        $issues = $this->analyze('psalm.xml');

        $this->assertSame(
            [5, 12, 17, 23],
            $this->linesFor($issues, 'RedundantCondition', 'resources/views/match-outside-arms.blade.php'),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * #1559: `$this`/`self::` in a plain template (no enclosing `@php class`) is classless global
     * scope from Psalm's point of view, so every mention floods `InvalidScope`/`NonStaticSelfCall` —
     * conventions Livewire/Filament templates lean on heavily. Neither issue type may appear on this
     * template at all.
     */
    #[Test]
    public function this_and_self_outside_a_class_report_no_scope_issues(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'resources/views/this-self-outside-class.blade.php';

        // Guard against a vacuous pass: prove the fixture actually compiled all three flooding
        // expressions into the shadow before asserting the absence of findings on it.
        $shadow = $this->shadowSourceFor($template);
        $this->assertStringContainsString('$this->method()', $shadow);
        $this->assertStringContainsString('self::bar()', $shadow);
        $this->assertStringContainsString('static::baz()', $shadow);

        $this->assertSame(
            [],
            $this->linesFor($issues, 'InvalidScope', $template),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
        $this->assertSame(
            [],
            $this->linesFor($issues, 'NonStaticSelfCall', $template),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * #1559 negative: `@php class ... @endphp` compiles a REAL class into the shadow (#581), so
     * `self::` calling a non-static sibling and `$this` inside a static method are genuine author
     * mistakes, not compiler bookkeeping, and must keep reporting on the template.
     */
    #[Test]
    public function this_and_self_inside_a_compiled_class_still_report_genuine_scope_issues(): void
    {
        $issues = $this->analyze('psalm.xml');
        $template = 'resources/views/this-self-inside-compiled-class.blade.php';

        // Guard against a vacuous pass: if this fixture's @php block ever stopped compiling a real
        // class into the shadow, the assertions below would pass with nothing left to find.
        $shadow = $this->shadowSourceFor($template);
        $this->assertStringContainsString(
            'class BladeScopeCompiledClass1559',
            $shadow,
            "the fixture's @php class block never compiled a real class into the shadow:\n" . $shadow,
        );

        $this->assertSame(
            [10],
            $this->linesFor($issues, 'NonStaticSelfCall', $template),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
        // Line 15: `$this` inside a static method. Line 21: a non-static closure created inside a
        // static method — ClosureAnalyzer.php only threads `$this` into a closure's scope when
        // neither the enclosing method nor the closure itself is static, so this `$this->` read
        // genuinely has no `$this` in scope too, even though it sits lexically inside a class
        // (#1559 fix round).
        $this->assertSame(
            [15, 21],
            $this->linesFor($issues, 'InvalidScope', $template),
            \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /** Every compiled shadow's source of that run, concatenated. */
    private function allShadowSources(string $config = 'psalm.xml'): string
    {
        $shadows = $this->fixtureShadows(self::FIXTURE, $this->arguments($config));
        $this->assertDirectoryExists($shadows, 'no shadow was ever written for this run');

        $source = '';

        foreach (\array_diff(\scandir($shadows) ?: [], ['.', '..']) as $entry) {
            $source .= (string) \file_get_contents($shadows . '/' . $entry);
        }

        return $source;
    }

    /**
     * One template's own compiled shadow, matched via `manifest.php`'s shadow-path => template-path
     * map, rather than {@see allShadowSources()}'s whole-directory concatenation: a fixture-wide
     * substring count cannot tell THIS template's compiled output apart from every other fixture's.
     */
    private function shadowSourceFor(string $template, string $config = 'psalm.xml'): string
    {
        $shadows = $this->fixtureShadows(self::FIXTURE, $this->arguments($config));
        $manifestPath = $shadows . '/manifest.php';
        $this->assertFileExists($manifestPath, 'no shadow manifest was ever written for this run');

        $manifest = require $manifestPath;
        $this->assertIsArray($manifest);

        foreach ($manifest as $shadowPath => $entry) {
            $templatePath = \is_array($entry) ? ($entry[0] ?? null) : null;

            if (\is_string($templatePath) && \str_ends_with($templatePath, $template) && \is_string($shadowPath)) {
                // The manifest names the fixture's own cache directory, which a later run of the
                // same fixture wipes; read the copy taken when this run finished.
                return (string) \file_get_contents($shadows . '/' . \basename($shadowPath));
            }
        }

        $this->fail("no shadow was compiled for {$template}");
    }

    /**
     * #1909: an undeclared variable is `mixed`, and Psalm narrows (`?? false`, `?->`) or coerces (a
     * `mixed` argument to `?BaseModel`) it into findings outside the Mixed family. They are the
     * fallback type's artifact, so `reportMixedIssues` governs them too.
     */
    #[Test]
    public function findings_about_undeclared_variables_follow_report_mixed_issues(): void
    {
        $template = 'mixed-leak-undeclared.blade.php';
        $expected = [
            'RiskyTruthyFalsyComparison' => [1, 4],
            'UndefinedMagicMethod' => [8],
            'PossiblyInvalidArgument' => [10],
            'PossiblyInvalidCast' => [10],
        ];

        foreach (['psalm.xml' => false, 'psalm-blade-report-mixed.xml' => true] as $config => $reported) {
            $issues = $this->analyze($config);
            $json = \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);

            foreach ($expected as $type => $lines) {
                $this->assertSame($reported ? $lines : [], $this->linesFor($issues, $type, $template), "{$config} {$type}: {$json}");
            }
        }
    }

    /**
     * #1909: the same findings on a variable the template declares (raw `@var`, multi-line included,
     * and `@psalm-var`), assigns, binds as a loop alias, or gets from a class component keep
     * reporting with `reportMixedIssues` off.
     */
    #[Test]
    public function findings_about_declared_and_local_variables_keep_reporting(): void
    {
        $issues = $this->analyze('psalm.xml');
        $json = \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);

        $this->assertSame([2, 16], $this->linesFor($issues, 'RiskyTruthyFalsyComparison', 'mixed-leak-declared.blade.php'), $json);
        $this->assertSame([7], $this->linesFor($issues, 'UndefinedMagicMethod', 'mixed-leak-declared.blade.php'), $json);
        $this->assertSame([2, 6], $this->linesFor($issues, 'RiskyTruthyFalsyComparison', 'mixed-leak-local.blade.php'), $json);
        // A docblock naming a callable parameter (`$value`) still declares `$box`.
        $this->assertSame([20], $this->linesFor($issues, 'UndefinedMethod', 'mixed-leak-declared.blade.php'), $json);
        // A compound assignment and a by-reference output argument bind the name too.
        $this->assertSame([9], $this->linesFor($issues, 'InvalidStringClass', 'mixed-leak-local.blade.php'), $json);
        $this->assertSame([11], $this->linesFor($issues, 'PossiblyInvalidArgument', 'mixed-leak-local.blade.php'), $json);
        // Typed by the class component's properties, though its prelude still writes `@var mixed`.
        $this->assertSame([2], $this->linesFor($issues, 'UndefinedMagicMethod', 'components/mixed-leak-card.blade.php'), $json);
        $this->assertSame([4], $this->linesFor($issues, 'RiskyTruthyFalsyComparison', 'components/mixed-leak-card.blade.php'), $json);
    }

    /**
     * #1909: `$acct['avatar']` on a possible `ArrayAccess` runs a virtual `offsetGet()` that Psalm
     * analyzes with `PossiblyInvalidMethodCall` suppressed (ArrayFetchAnalyzer.php); the re-emit lost
     * that suppression under either config.
     */
    #[Test]
    public function array_access_on_a_possible_array_access_does_not_report_the_virtual_offset_call(): void
    {
        foreach (['psalm.xml', 'psalm-blade-report-mixed.xml'] as $config) {
            $issues = $this->analyze($config);

            $this->assertSame(
                [],
                $this->linesFor($issues, 'PossiblyInvalidMethodCall', 'undeclared-isset-offset.blade.php'),
                $config . ': ' . \json_encode($issues, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
            );
        }
    }
}
