<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\ViewReferenceCollector;
use Psalm\LaravelPlugin\Blade\ViewReferenceRegistry;
use Psalm\LaravelPlugin\Handlers\Views\UnusedViewHandler;
use Symfony\Component\Process\Process;

/**
 * End-to-end proof that a Blade template with no statically-provable reference is reported
 * ({@see \Psalm\LaravelPlugin\Issues\UnusedView}), and that one unresolvable reference anywhere
 * in the project turns the whole check off for the run.
 *
 * A real `vendor/bin/psalm` run is the only way to pin this: reference collection spans both the
 * compile pass (template-side, `@include`/`@extends`) and a full `AfterCodebasePopulated` walk of
 * every project file (call-site, `view()`), so nothing short of a real run exercises both halves
 * together.
 */
#[CoversClass(UnusedViewHandler::class)]
#[CoversClass(ViewReferenceCollector::class)]
#[CoversClass(ViewReferenceRegistry::class)]
final class UnusedViewTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/Fixtures/UnusedView';

    private const NAMESPACED_FIXTURE = __DIR__ . '/Fixtures/UnusedViewNamespaced';

    private const DYNAMIC_FIXTURE = __DIR__ . '/Fixtures/UnusedViewDynamic';

    private const DIRECTIVES_FIXTURE = __DIR__ . '/Fixtures/UnusedViewDirectives';

    private const COMPONENT_TAG_FIXTURE = __DIR__ . '/Fixtures/UnusedViewComponentTag';

    private const FIRST_CALL_FIXTURE = __DIR__ . '/Fixtures/UnusedViewFirstCall';

    private const PARSE_FAILURE_FIXTURE = __DIR__ . '/Fixtures/UnusedViewParseFailure';

    private const SUPPRESSED_FIXTURE = __DIR__ . '/Fixtures/UnusedViewSuppressed';

    private const INSTANCE_CALL_FIXTURE = __DIR__ . '/Fixtures/UnusedViewInstanceCall';

    private const FACADE_ALIAS_FIXTURE = __DIR__ . '/Fixtures/UnusedViewFacadeAlias';

    private const FACADE_ALIAS_DYNAMIC_FIXTURE = __DIR__ . '/Fixtures/UnusedViewFacadeAliasDynamic';

    private const NUMERIC_NAME_FIXTURE = __DIR__ . '/Fixtures/UnusedViewNumericName';

    private const ISSUE = 'UnusedView';

    /** @var list<string> */
    private const FIXTURES = [
        self::FIXTURE,
        self::NAMESPACED_FIXTURE,
        self::DYNAMIC_FIXTURE,
        self::DIRECTIVES_FIXTURE,
        self::COMPONENT_TAG_FIXTURE,
        self::FIRST_CALL_FIXTURE,
        self::PARSE_FAILURE_FIXTURE,
        self::SUPPRESSED_FIXTURE,
        self::INSTANCE_CALL_FIXTURE,
        self::FACADE_ALIAS_FIXTURE,
        self::FACADE_ALIAS_DYNAMIC_FIXTURE,
        self::NUMERIC_NAME_FIXTURE,
    ];

    protected function setUp(): void
    {
        foreach (self::FIXTURES as $fixture) {
            $this->deleteShadowDir($fixture);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::FIXTURES as $fixture) {
            $this->deleteShadowDir($fixture);
        }
    }

    private function deleteShadowDir(string $fixture): void
    {
        $shadowDir = $fixture . '/.cache/blade-shadows';

        if (!\is_dir($shadowDir)) {
            return;
        }

        foreach (\array_diff(\scandir($shadowDir) ?: [], ['.', '..']) as $entry) {
            \unlink($shadowDir . '/' . $entry);
        }

        \rmdir($shadowDir);
    }

    /**
     * @return list<array{type: string, file: string, message: string}>
     */
    private function unusedViewIssues(string $fixture, string $config): array
    {
        $psalmBinary = \dirname(__DIR__, 3) . '/vendor/bin/psalm';
        $this->assertFileExists($psalmBinary, 'Psalm binary not found — run composer install.');

        $process = new Process(
            [\PHP_BINARY, $psalmBinary, '-c', $config, '--no-cache', '--threads=1', '--no-progress', '--output-format=json'],
            $fixture,
        );
        $process->setTimeout(300);
        // Not mustRun(): the fixture reports an issue on purpose.
        $process->run();

        $decoded = \json_decode($process->getOutput(), true);
        $this->assertIsArray($decoded, "Psalm did not emit a JSON report.\n{$process->getOutput()}\n{$process->getErrorOutput()}");

        $issues = [];

        foreach ($decoded as $issue) {
            $this->assertIsArray($issue);
            $type = (string) $issue['type'];

            if ($type !== self::ISSUE) {
                continue;
            }

            $issues[] = [
                'type' => $type,
                'file' => \basename((string) $issue['file_name']),
                'message' => (string) $issue['message'],
            ];
        }

        return $issues;
    }

    #[Test]
    public function a_template_no_call_site_ever_renders_is_reported(): void
    {
        $issues = $this->unusedViewIssues(self::FIXTURE, 'psalm.xml');

        $this->assertCount(1, $issues, \var_export($issues, true));
        $this->assertSame('orphan.blade.php', $issues[0]['file']);
        $this->assertStringContainsString('orphan', $issues[0]['message']);
    }

    /**
     * A template registered only through `loadViewsFrom($dir, $namespace)` — a finder namespace
     * hint, never `getPaths()` — must be discovered at all (#1497). Before the fix it was invisible
     * to discovery entirely, so `orphan.blade.php` under the same hint directory could never be
     * flagged unused; that missing report is the teeth this test pins.
     */
    #[Test]
    public function a_namespace_hint_orphan_is_reported_and_a_referenced_one_is_not(): void
    {
        $issues = $this->unusedViewIssues(self::NAMESPACED_FIXTURE, 'psalm.xml');

        $orphaned = \array_values(\array_filter(
            $issues,
            static fn(array $issue): bool => $issue['file'] === 'orphan.blade.php',
        ));
        $this->assertCount(1, $orphaned, 'a namespace-hint template with no call site must still be caught: ' . \var_export($issues, true));

        $this->assertSame([], \array_values(\array_filter(
            $issues,
            static fn(array $issue): bool => $issue['file'] === 'widget.blade.php',
        )), "view('pkg::widget') must be recognized as a reference to the namespaced template: " . \var_export($issues, true));
    }

    /**
     * `123.blade.php` is a legal view. Every store on the path to the report is keyed by view name,
     * and PHP casts a numeric-string key to int, so the name arrives at the `string`-typed reporter
     * as an int: without the cast the run dies on a TypeError instead of reporting one orphan.
     */
    #[Test]
    public function a_numeric_view_name_is_reported_rather_than_throwing(): void
    {
        $issues = $this->unusedViewIssues(self::NUMERIC_NAME_FIXTURE, 'psalm.xml');

        $this->assertCount(1, $issues, \var_export($issues, true));
        $this->assertSame('123.blade.php', $issues[0]['file']);
    }

    /**
     * A partial only ever reached through `@include` from another template must not be reported —
     * template-side references are collected at compile time from the compiled shadow.
     */
    #[Test]
    public function a_template_only_reached_through_include_is_not_reported(): void
    {
        $issues = $this->unusedViewIssues(self::FIXTURE, 'psalm.xml');

        $this->assertSame([], \array_values(\array_filter(
            $issues,
            static fn(array $issue): bool => $issue['file'] === 'partial.blade.php',
        )));
    }

    /**
     * A layout only ever reached through `@extends` must not be reported, for the same reason as
     * the `@include`d partial.
     */
    #[Test]
    public function a_template_only_reached_through_extends_is_not_reported(): void
    {
        $issues = $this->unusedViewIssues(self::FIXTURE, 'psalm.xml');

        $this->assertSame([], \array_values(\array_filter(
            $issues,
            static fn(array $issue): bool => $issue['file'] === 'layout.blade.php',
        )));
    }

    /**
     * One `@include($name)` with a non-literal argument anywhere in the project makes the whole
     * reference set unreliable, so the check must decline for every template in the run — including
     * the otherwise-orphan `orphan.blade.php`.
     */
    #[Test]
    public function a_dynamic_reference_anywhere_turns_the_check_off_for_the_whole_run(): void
    {
        $issues = $this->unusedViewIssues(self::DYNAMIC_FIXTURE, 'psalm.xml');

        $this->assertSame([], $issues, \var_export($issues, true));
    }

    #[Test]
    public function the_check_is_off_unless_the_config_flag_opts_in(): void
    {
        $issues = $this->unusedViewIssues(self::FIXTURE, 'psalm-unused-off.xml');

        $this->assertSame([], $issues, \var_export($issues, true));
    }

    /**
     * Templates referenced only through `@each`, `@component`, or `@includeWhen` must not be
     * reported, AND the rule must stay ON for the rest of the run: an unrelated orphan template in
     * the same project is still flagged. A bare "zero issues" assertion here would pass just as well
     * if these directives accidentally disabled the whole rule, which is exactly the bug this
     * fixture pins.
     */
    #[Test]
    public function templates_reached_only_through_each_component_or_includewhen_are_not_reported(): void
    {
        $issues = $this->unusedViewIssues(self::DIRECTIVES_FIXTURE, 'psalm.xml');

        foreach (['row.blade.php', 'card.blade.php', 'whenp.blade.php'] as $file) {
            $this->assertSame([], \array_values(\array_filter(
                $issues,
                static fn(array $issue): bool => $issue['file'] === $file,
            )), "{$file} must not be reported unused");
        }

        $orphaned = \array_values(\array_filter(
            $issues,
            static fn(array $issue): bool => $issue['file'] === 'orphan.blade.php',
        ));
        $this->assertCount(1, $orphaned, 'the rule must still catch a genuine orphan in the same run: ' . \var_export($issues, true));
    }

    /**
     * A component tag (`<x-alert>`) resolves its view through `$component->resolveView()`, never a
     * literal, in the compiled shadow — this plugin cannot statically resolve it, so the whole check
     * declines for the run rather than risk reporting the component's own view as unused.
     */
    #[Test]
    public function a_component_tag_turns_the_check_off_for_the_whole_run(): void
    {
        $issues = $this->unusedViewIssues(self::COMPONENT_TAG_FIXTURE, 'psalm.xml');

        $this->assertSame([], $issues, \var_export($issues, true));
    }

    /**
     * `$items->first(fn ...)` in a template is not Blade — only a `$__env->first()` compiled from
     * `@includeFirst` counts. The rule must both stay silent about it AND stay ON: the fixture's
     * orphan template is still reported.
     */
    #[Test]
    public function an_unrelated_first_call_on_a_non_env_receiver_does_not_disable_the_rule(): void
    {
        $issues = $this->unusedViewIssues(self::FIRST_CALL_FIXTURE, 'psalm.xml');

        $orphaned = \array_values(\array_filter(
            $issues,
            static fn(array $issue): bool => $issue['file'] === 'orphan.blade.php',
        ));
        $this->assertCount(1, $orphaned, 'an unrelated ->first() call must not disable the rule: ' . \var_export($issues, true));
    }

    /**
     * A shadow that BLADE compiles without error can still fail to re-parse as PHP (a stray
     * `@endif` compiles to a bare `endif;` with no matching alternative-syntax `if:`, which is a
     * genuine PHP parse error). That failure must mark the reference set dynamic, the same as a
     * BladeCompileError: the template's own `@extends`/`@include` targets are unknown, not empty, so
     * they must not be reported unused, and neither should anything else in the run.
     */
    #[Test]
    public function a_shadow_that_fails_to_reparse_turns_the_check_off_for_the_whole_run(): void
    {
        $issues = $this->unusedViewIssues(self::PARSE_FAILURE_FIXTURE, 'psalm.xml');

        $this->assertSame([], $issues, \var_export($issues, true));
    }

    /**
     * `{{-- @psalm-suppress UnusedView --}}` has no following PHP statement to attach to in a
     * static-HTML-only template — the likeliest shape for a genuinely unused view — so suppression
     * has to be read straight off the template source, not the shadow's per-statement map.
     */
    #[Test]
    public function a_static_html_only_template_can_suppress_the_issue(): void
    {
        $issues = $this->unusedViewIssues(self::SUPPRESSED_FIXTURE, 'psalm.xml');

        $this->assertSame([], \array_values(\array_filter(
            $issues,
            static fn(array $issue): bool => $issue['file'] === 'suppressed.blade.php',
        )), \var_export($issues, true));

        $unsuppressed = \array_values(\array_filter(
            $issues,
            static fn(array $issue): bool => $issue['file'] === 'unsuppressed.blade.php',
        ));
        $this->assertCount(1, $unsuppressed, 'an identical template without the comment must still be reported: ' . \var_export($issues, true));
    }

    /**
     * `Factory::make()` and `response()->view()` are instance method calls on an arbitrary receiver,
     * not the `view()` helper or the `View` facade's static form — both must still be collected
     * (add-only), or the docs claiming `Factory::make()` support are simply wrong.
     */
    #[Test]
    public function instance_make_and_view_calls_are_collected(): void
    {
        $issues = $this->unusedViewIssues(self::INSTANCE_CALL_FIXTURE, 'psalm.xml');

        foreach (['used.blade.php', 'viewed.blade.php'] as $file) {
            $this->assertSame([], \array_values(\array_filter(
                $issues,
                static fn(array $issue): bool => $issue['file'] === $file,
            )), "{$file} must not be reported unused");
        }

        $orphaned = \array_values(\array_filter(
            $issues,
            static fn(array $issue): bool => $issue['file'] === 'orphan.blade.php',
        ));
        $this->assertCount(1, $orphaned, 'the rule must still catch a genuine orphan in the same run: ' . \var_export($issues, true));
    }

    /**
     * `use Illuminate\Support\Facades\View as ViewFacade; ViewFacade::make('used')` has to classify
     * by Psalm's resolved FQCN, not the bare (aliased) class name in the source — `getLast()` alone
     * would see "ViewFacade", never "View".
     */
    #[Test]
    public function an_aliased_view_facade_import_is_collected(): void
    {
        $issues = $this->unusedViewIssues(self::FACADE_ALIAS_FIXTURE, 'psalm.xml');

        $this->assertSame([], \array_values(\array_filter(
            $issues,
            static fn(array $issue): bool => $issue['file'] === 'used.blade.php',
        )), \var_export($issues, true));

        $orphaned = \array_values(\array_filter(
            $issues,
            static fn(array $issue): bool => $issue['file'] === 'orphan.blade.php',
        ));
        $this->assertCount(1, $orphaned, 'the rule must still catch a genuine orphan in the same run: ' . \var_export($issues, true));
    }

    /**
     * The other direction of the same alias-classification fix: a dynamic argument through the
     * ALIASED form must still trip the off-switch. Before the resolved-FQCN classification, an
     * aliased `ViewFacade::make($name)` matched no branch at all — neither collected nor flagged
     * dynamic — which is the false negative half of the bug.
     */
    #[Test]
    public function an_aliased_view_facade_call_with_a_dynamic_argument_turns_the_check_off(): void
    {
        $issues = $this->unusedViewIssues(self::FACADE_ALIAS_DYNAMIC_FIXTURE, 'psalm.xml');

        $this->assertSame([], $issues, \var_export($issues, true));
    }

    /**
     * A shadow cache warmed while `reportUnusedViews` was off stores a null references slot for
     * every template (collection never ran). Flipping the flag on against that SAME cache must not
     * leave every template "fresh" with no references forever: each one has to recompile once so the
     * collector actually runs.
     */
    #[Test]
    public function a_cache_warmed_with_the_flag_off_is_recompiled_once_the_flag_turns_on(): void
    {
        // Warm the shadow cache with reference collection off — every template's manifest entry gets
        // a null references slot.
        $this->unusedViewIssues(self::FIXTURE, 'psalm-unused-off.xml');

        // Same fixture, same cache directory, flag now on, deliberately without deleting the cache
        // between the two runs.
        $issues = $this->unusedViewIssues(self::FIXTURE, 'psalm.xml');

        $this->assertCount(1, $issues, \var_export($issues, true));
        $this->assertSame('orphan.blade.php', $issues[0]['file']);

        foreach (['layout.blade.php', 'partial.blade.php'] as $file) {
            $this->assertSame([], \array_values(\array_filter(
                $issues,
                static fn(array $issue): bool => $issue['file'] === $file,
            )), "{$file} must not be reported unused after the flag turns on");
        }
    }
}
