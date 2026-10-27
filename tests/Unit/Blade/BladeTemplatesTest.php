<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Bootstrap\ApplicationProvider;

/**
 * Template regressions that only reproduce once a fixture's templates run through the Blade shadow
 * compiler and a real `vendor/bin/psalm` analysis: findings on compiler-generated code that must be
 * dropped, the author's own code that must keep reporting, templates the compiler throws on, and
 * helpers the booted app defined.
 *
 * ONE forked run over `Fixtures/BladeTemplates` serves every case (a Psalm boot costs several seconds
 * whatever the fixture's size), so each template is a single-purpose file named for what it pins and a
 * case asserts only on its own templates. `--debug` prints the "Forking analysis" line that proves the
 * run actually forked: the runtime-helper injection is made in the parent and reaches the workers only
 * by copy-on-write.
 */
#[CoversNothing]
#[Group('subprocess')]
final class BladeTemplatesTest extends TestCase
{
    use AnalysesFixtureApp;

    private const FIXTURE = __DIR__ . '/Fixtures/BladeTemplates';

    /** Forking only happens when there are more files to analyze than workers. */
    private const THREADS = 4;

    /** @var list<array{type: string, file: string, line: int, message: string}>|null */
    private static ?array $issues = null;

    /** The raw `--debug` output, for the fork proof and for failure messages. */
    private static string $output = '';

    /** The shadow directory this run left behind (a snapshot, see {@see AnalysesFixtureApp}). */
    private static string $shadows = '';

    protected static function resetFixtureAnalyses(): void
    {
        self::$issues = null;
        self::$output = '';
        self::$shadows = '';
    }

    /**
     * The report goes to a file so `--debug` can occupy stdout. Info-level issues are dropped: they
     * are what the fixture's purity handlers downgrade, and `--report` keeps them where stdout does not.
     *
     * @return list<array{type: string, file: string, line: int, message: string}>
     */
    private function issues(): array
    {
        if (self::$issues !== null) {
            return self::$issues;
        }

        $report = \sys_get_temp_dir() . '/blade-templates-' . \getmypid() . '.json';

        try {
            $run = $this->analyzeFixture(self::FIXTURE, [
                '-c', 'psalm.xml', '--no-cache', '--threads=' . self::THREADS, '--scan-threads=' . self::THREADS,
                '--debug', '--report=' . $report,
            ]);
            self::$output = $run['output'] . $run['errorOutput'];
            self::$shadows = $run['shadows'];

            $this->assertFileExists($report, "Psalm wrote no report.\n" . self::$output);
            $raw = (string) \file_get_contents($report);
        } finally {
            if (\is_file($report)) {
                \unlink($report);
            }
        }

        $issues = [];

        foreach ($this->decodeIssues(['output' => $raw, 'errorOutput' => self::$output, 'shadows' => '']) as $issue) {
            if ($issue['severity'] === 'info') {
                continue;
            }

            $issues[] = [
                'type' => (string) $issue['type'],
                'file' => \basename((string) $issue['file_name']),
                'line' => (int) $issue['line_from'],
                'message' => (string) $issue['message'],
            ];
        }

        // Positive control for every silence case below: `php-e` is the author's own `e()` call, which must
        // always reach the analyzer and report. Without it, shadows that never reached analysis would pass
        // as silent.
        $this->assertContains(
            'PossiblyInvalidArgument',
            \array_column(\array_filter($issues, static fn(array $issue): bool => $issue['file'] === 'php-e.blade.php'), 'type'),
            "No template finding reached the report, so silence proves nothing.\n" . self::$output,
        );

        return self::$issues = $issues;
    }

    /** @return list<array{type: string, file: string, line: int, message: string}> */
    private function in(string $file): array
    {
        return \array_values(\array_filter($this->issues(), static fn(array $issue): bool => $issue['file'] === $file));
    }

    /** @return list<string> */
    private function typesIn(string $file): array
    {
        return \array_column($this->in($file), 'type');
    }

    /**
     * A silent assertion on a template proves nothing if the template never compiled. The manifest
     * records every template that made it through `compileAll()` this run, keyed by its real path.
     */
    private function assertCompiled(string $template): void
    {
        $this->issues();

        $path = \realpath(self::FIXTURE . '/resources/views/' . $template);
        // A missing template makes realpath() false, and an empty needle matches ANY manifest.
        $this->assertIsString($path, "{$template} does not exist on disk.");
        $this->assertStringContainsString(
            $path,
            (string) \file_get_contents(self::$shadows . '/manifest.php'),
            "{$template} was never compiled into a shadow, so its silence proves nothing.",
        );
    }

    private function assertSilent(string $template): void
    {
        $this->assertCompiled($template);
        $this->assertSame([], $this->in($template), $template);
    }

    // Issue #1535: an echo construct Blade synthesized (`{{ }}`, `{{{ }}}`, `{!! !!}`) never reports
    // `PossiblyInvalidArgument`/`PossiblyFalseArgument`, because the author never wrote that call.

    /** `{{ }}` compiles through `$echoFormat` and `{{{ }}}` through a hard-coded `e(`; `{!! !!}` is a bare `echo`. */
    #[Test]
    #[DataProvider('generatedEchos')]
    public function a_compiler_generated_echo_of_a_union_is_silent(string $template): void
    {
        $this->assertSilent($template);
    }

    /** @return iterable<string, array{string}> */
    public static function generatedEchos(): iterable
    {
        yield 'escaped union' => ['esc-union.blade.php'];
        yield 'triple-brace union' => ['triple-union.blade.php'];
        yield 'raw union' => ['raw-union.blade.php'];
        yield 'escaped falsable' => ['esc-false.blade.php'];
    }

    /**
     * The gate is anchored on the author-written call, not on the union. `@php echo e($x); @endphp`
     * and `{{ $x }}` compile to byte-identical shadow lines, so only looking for the call in the raw
     * template tells them apart. Blade also rewrites raw text INSIDE an author's own expression
     * (`@@foo`, component-class markers), so a generated echo whose argument was rewritten keeps its
     * issue too: noise on a rare shape, in exchange for never dropping a rewritten author call.
     *
     * @param list<string> $expected types, each of which the template must report
     */
    #[Test]
    #[DataProvider('authorsOwnCalls')]
    public function an_authors_own_call_or_a_wholly_invalid_argument_still_reports(string $template, array $expected): void
    {
        $this->assertCompiled($template);

        foreach ($expected as $type) {
            $this->assertContains($type, $this->typesIn($template), \var_export($this->in($template), true));
        }
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function authorsOwnCalls(): iterable
    {
        yield 'e() inside @php' => ['php-e.blade.php', ['PossiblyInvalidArgument']];
        yield 'echo inside @php' => ['php-echo.blade.php', ['PossiblyInvalidArgument']];
        yield 'e() nested in an echo' => ['nested-e.blade.php', ['PossiblyInvalidArgument']];
        yield 'e() whose argument Blade unescaped' => ['escaped-directive.blade.php', ['PossiblyInvalidArgument']];
        yield 'e() whose argument had a component marker stripped' => ['component-marker.blade.php', ['PossiblyInvalidArgument']];
        yield 'generated echo whose argument Blade rewrote' => ['escaped-directive-generated.blade.php', ['PossiblyInvalidArgument']];
        yield 'a user function inside an echo' => ['user-fn.blade.php', ['PossiblyInvalidArgument']];
        yield 'a definite array (only POSSIBLY-* are gated)' => ['definite-array.blade.php', ['InvalidArgument']];
    }

    /** The nested `e(e($x))`: the outer `e` is the compiler's and receives a `string`, so exactly the inner one reports. */
    #[Test]
    public function only_the_inner_call_of_a_nested_escape_reports(): void
    {
        $this->assertCompiled('nested-e.blade.php');
        $this->assertSame(['PossiblyInvalidArgument'], $this->typesIn('nested-e.blade.php'));
    }

    // Issue #1534: `e()`'s stub accepts `\Stringable`; other `ImplicitToStringCast` sites still report.

    #[Test]
    public function an_escaped_echo_of_a_stringable_is_silent_but_a_plain_function_still_reports(): void
    {
        $this->assertSilent('esc-stringable.blade.php');
        $this->assertSame(['ImplicitToStringCast'], $this->typesIn('php-strlen.blade.php'));
    }

    // Nullable echo: `e()`'s `@param` allows null, while a nullable receiver further along a chain reports.

    #[Test]
    public function an_escaped_echo_of_a_nullable_is_silent_but_a_nullable_chain_receiver_still_reports(): void
    {
        $this->assertSilent('esc-nullable.blade.php');
        $this->assertSame(['PossiblyNullPropertyFetch', 'PossiblyNullReference'], $this->typesIn('chain-kept.blade.php'));
    }

    // Issue #1566: `@error('field')` compiles to `$__errorArgs[1] ?? 'default'` on a one-element list.

    #[Test]
    public function the_compiled_error_args_offset_is_dropped_and_the_authors_own_offset_survives(): void
    {
        $this->assertCompiled('error-offset.blade.php');

        $offsets = \array_values(\array_filter($this->in('error-offset.blade.php'), static fn(array $issue): bool => $issue['type'] === 'InvalidArrayOffset'));

        // Exactly the author's `$arr = ['only']; echo $arr[1];` on line 8: the gate is exact-name on `$__errorArgs`.
        $this->assertCount(1, $offsets, \var_export($this->in('error-offset.blade.php'), true));
        $this->assertStringContainsString('$arr', $offsets[0]['message']);
        $this->assertSame(8, $offsets[0]['line']);
    }

    // Issue #1542: `ManagesLoops` has no stub, so `getLastLoop()` leaked a nullable `$loop` into every
    // `@foreach` and `addLoop()` rejected a paginator contract.

    #[Test]
    public function loop_property_access_and_a_stdclass_typed_wrapper_around_getlastloop_are_silent(): void
    {
        $this->assertSilent('foreach.blade.php');
        // The real getLastLoop() returns `(object) $last`, a \stdClass instance: a plain `object{...}` stub return would
        // make a `?\stdClass` wrapper report LessSpecificReturnStatement.
        $this->assertSame([], $this->in('LoopWrapper.php'));
    }

    /**
     * `$loop->parent` carries the enclosing loop's own shape and stays nullable: a depth-1
     * `@if ($loop->parent)` guard must not turn into a redundant-condition finding. Inside a `@foreach`
     * the type comes from the `getLastLoop()` stub; in a partial with no loop of its own, from the prelude.
     */
    #[Test]
    public function loop_parent_carries_the_enclosing_loop_shape(): void
    {
        $this->assertCompiled('nested.blade.php');
        $this->assertCompiled('included-in-loop.blade.php');
        $this->assertSame([['Trace', '$parentIteration: int']], $this->typesAndMessages('nested.blade.php'));
        $this->assertSame([['Trace', '$parentIteration: int|null']], $this->typesAndMessages('included-in-loop.blade.php'));
    }

    /** @return list<array{string, string}> */
    private function typesAndMessages(string $file): array
    {
        return \array_map(static fn(array $issue): array => [$issue['type'], $issue['message']], $this->in($file));
    }

    /**
     * A bare `Paginator` contract declares no `Traversable`, so RawObjectIteration/UnusedForeachValue stay
     * correct; what the stub removes is the InvalidArgument `addLoop()`'s old `\Countable|array` param raised.
     */
    #[Test]
    public function foreach_over_a_paginator_contract_no_longer_faults_addloop_but_a_non_iterable_still_reports(): void
    {
        $this->assertCompiled('paginator.blade.php');
        $this->assertNotContains('InvalidArgument', $this->typesIn('paginator.blade.php'));

        $this->assertCompiled('non-iterable.blade.php');
        $this->assertContains('InvalidIterator', $this->typesIn('non-iterable.blade.php'));
    }

    // Issue #1545 (needs `sealAllProperties="true"`, which the fixture's psalm.xml sets): Psalm reports a
    // sealed magic property through two emission sites for the same access.

    /**
     * `UndefinedMagicPropertyFetch` is reported at the real node and fires unconditionally once the
     * receiver has a magic getter, so the synthesized `UndefinedThisPropertyFetch` twin (no location,
     * always unmapped) is dropped, across a variable, a static-call result and an array offset.
     */
    #[Test]
    public function the_fetch_duplicate_is_dropped_across_every_receiver_shape(): void
    {
        $this->assertCompiled('missing-prop.blade.php');

        $this->assertNotContains('UndefinedThisPropertyFetch', $this->typesIn('missing-prop.blade.php'));

        $fetchLines = \array_column(
            \array_filter($this->in('missing-prop.blade.php'), static fn(array $issue): bool => $issue['type'] === 'UndefinedMagicPropertyFetch'),
            'line',
        );
        \sort($fetchLines);
        // `{{ $magic->missing }}`, `{{ \Fx\Magic::make()->missing }}`, `{{ $objects[0]->missing }}`.
        $this->assertSame([6, 10, 11], $fetchLines);
    }

    /**
     * The `__set` counterpart is NOT dropped: its mapped twin needs a resolved `$var_id`, so for a
     * non-variable receiver (`\Fx\Magic::make()->nope = 1`) the unmapped `UndefinedThisPropertyAssignment`
     * is the ONLY diagnostic. The plain-variable receiver on line 8 keeps both, a real duplicate.
     */
    #[Test]
    public function the_assignment_duplicate_is_kept_because_its_twin_is_not_unconditional(): void
    {
        $this->assertCompiled('missing-prop.blade.php');
        $reported = $this->in('missing-prop.blade.php');

        $unmapped = \array_values(\array_filter($reported, static fn(array $issue): bool => $issue['type'] === 'UndefinedThisPropertyAssignment'));
        $this->assertCount(1, $unmapped, \var_export($reported, true));
        $this->assertSame(1, $unmapped[0]['line']);
        $this->assertStringContainsString('(unmapped)', $unmapped[0]['message']);

        $mapped = \array_values(\array_filter($reported, static fn(array $issue): bool => $issue['type'] === 'UndefinedMagicPropertyAssignment'));
        $this->assertSame([8], \array_column($mapped, 'line'), \var_export($reported, true));
    }

    // A template the real Blade compiler throws on is reported as an issue on the `.blade.php` file.

    #[Test]
    public function a_template_the_compiler_throws_on_is_reported_as_an_error_at_its_first_line(): void
    {
        $failures = \array_values(\array_filter($this->in('broken.blade.php'), static fn(array $issue): bool => $issue['type'] === 'BladeCompileError'));

        // The directive sits on line 2, but the exception carries a line of the provider file, not of the template.
        $this->assertCount(1, $failures, \var_export($this->issues(), true));
        $this->assertSame(1, $failures[0]['line']);
        $this->assertSame('Template not analyzed: the explode directive needs a request', $failures[0]['message']);
    }

    /**
     * Prose `@component` reaches Laravel's compileComponent(null), whose str_contains(null) deprecation
     * Psalm's own error handler would turn into a message carrying the vendor path and the full command line.
     */
    #[Test]
    public function a_php_error_raised_by_the_compiler_names_the_directive_instead_of_the_psalm_cli_args(): void
    {
        $failures = $this->in('prose-directive.blade.php');

        $this->assertSame(['BladeCompileError'], \array_column($failures, 'type'), \var_export($failures, true));
        $this->assertSame(
            'Template not analyzed: @component needs arguments; write @@component to print it as text',
            $failures[0]['message'],
        );
    }

    #[Test]
    public function the_other_templates_are_still_analyzed_and_issue_handlers_suppress_a_failure_per_template_path(): void
    {
        $this->assertSame([['UndefinedFunction', 2]], \array_map(
            static fn(array $issue): array => [$issue['type'], $issue['line']],
            $this->in('healthy.blade.php'),
        ));

        // `broken-suppressed.blade.php` fails to compile the same way as `broken.blade.php`; psalm.xml suppresses it by path.
        $this->assertSame([], $this->in('broken-suppressed.blade.php'));
    }

    // Issue #1551: a helper function a service provider `include`s at boot is invisible to Blade shadows,
    // because Psalm resolves a bare call through the ROOT file's `declaring_function_ids`.

    /**
     * Existence alone would be cheap to fake. `TooManyArguments` can only come from `Functions::getStorage()`
     * walking the injected map to the helper's real `FunctionStorage`, so it proves params and return
     * types resolve too.
     */
    #[Test]
    public function a_helper_and_a_constant_the_booted_app_defined_resolve_inside_a_template(): void
    {
        $this->assertCompiled('runtime-helpers.blade.php');
        $reported = $this->in('runtime-helpers.blade.php');
        $messages = \implode("\n", \array_column($reported, 'message'));

        $this->assertStringNotContainsString('Function demo_helper', $messages, $messages);
        $this->assertStringNotContainsString('DEMO_CONST', $messages, $messages);
        $this->assertStringContainsString('Too many arguments for demo_helper', $messages, $messages);
    }

    /**
     * A helper file's storage also carries symbols it merely CONTAINS (a declaration behind a version
     * gate), which the runtime never declared, so those diagnostics must survive the injection. The blast
     * radius is shadows only: a project file calling the same helper keeps Psalm's own answer.
     */
    #[Test]
    public function symbols_the_boot_never_declared_and_project_files_still_report(): void
    {
        $this->assertCompiled('runtime-helpers.blade.php');
        $messages = \array_column($this->in('runtime-helpers.blade.php'), 'message');

        foreach (['definitely_not_defined', 'demo_never_declared', 'DEMO_NEVER_DEFINED'] as $symbol) {
            $this->assertCount(1, \array_filter($messages, static fn(string $message): bool => \str_contains($message, $symbol)), $symbol);
        }

        $this->assertContains('UndefinedFunction', $this->typesIn('PlainCaller.php'));
    }

    // Issue #2128: an AliasLoader alias that points at an enum cannot be modelled as `class Alias extends \Enum {}`.

    /** The template and the PHP file reach `Fx\Suit` only through the global `Suit` alias; the PHP file also pins the case type. */
    #[Test]
    public function an_enum_case_resolves_through_an_alias_in_a_template_and_a_php_file(): void
    {
        $this->assertSilent('enum-alias.blade.php');
        $this->assertSilent('enum-alias-unscanned.blade.php');
        // Like every fixture class nothing calls it, so UnusedClass is expected; anything else is the alias failing.
        $this->assertSame(['UnusedClass'], $this->typesIn('EnumAliasConsumer.php'), \var_export($this->in('EnumAliasConsumer.php'), true));
    }

    /** Psalm silently clamps `--threads` to 1 on Windows or without pcntl, so the proof would fail there instead of proving anything. */
    #[Test]
    public function the_run_forked_so_the_injection_reached_workers(): void
    {
        if (\defined('PHP_WINDOWS_VERSION_MAJOR') || !\extension_loaded('pcntl')) {
            self::markTestSkipped('Psalm cannot fork analysis workers in this environment.');
        }

        $this->issues();

        $this->assertStringContainsString('Forking analysis', self::$output, 'The run never forked, so it proves nothing about workers.');
    }

    /** Static state carrying per-run facts must not survive into the next plugin invocation. */
    #[Test]
    public function the_captured_helper_files_are_cleared_on_reset(): void
    {
        ApplicationProvider::reset();

        $this->assertSame([], ApplicationProvider::runtimeDeclaredFunctionFiles());
        $this->assertSame([], ApplicationProvider::runtimeDeclaredFunctionIds());
        $this->assertSame([], ApplicationProvider::runtimeDeclaredConstants());
    }
}
