<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
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
        $issues = [];

        foreach ($this->fixtureIssues(self::FIXTURE, $this->arguments($config)) as $issue) {
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

    /**
     * Every class name PreludeBuilder can emit into a prelude, see PreludeBuilder::ambientClassNames().
     * `Illuminate\View\Component` never appears in a real prelude any more (#1525: `$component` is
     * never declared), so its assertion below is vacuous — kept as a regression guard against
     * reintroducing that declaration.
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
     * so neither `$component` nor `$attributes` is declared a type in its own prelude any more —
     * both fall through to `mixed`, and Psalm cannot judge a `mixed` guard redundant.
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
     * prelude only ever declares it `mixed` through the undeclared-name fallback), unlike
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
}
