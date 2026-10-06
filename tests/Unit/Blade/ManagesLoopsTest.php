<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins issue #1542: `Illuminate\View\Concerns\ManagesLoops` has no stub, so two defects leak into
 * every `@foreach` Blade compiles.
 *
 * `getLastLoop()`'s else-branch has no return, so Psalm infers `stdClass|null` for it; the
 * compiled `@foreach` does `$loop = $__env->getLastLoop();`, which overwrites the shadow
 * prelude's precise ambient `$loop` type (see PreludeBuilder::AMBIENT_TYPES) with that nullable
 * type, producing PossiblyNullPropertyFetch on every `$loop->foo` access.
 *
 * `addLoop()` declares `@param \Countable|array $data`, but `Illuminate\Contracts\Pagination\
 * Paginator` extends neither Countable nor array, so every `@foreach` over a variable typed to
 * that contract reports InvalidArgument.
 *
 * A real `vendor/bin/psalm` run is the only way to pin either: both defects only surface once the
 * fixture's templates run through the Blade shadow compiler.
 */
#[CoversNothing]
#[Group('subprocess')]
final class ManagesLoopsTest extends TestCase
{
    use AnalysesFixtureApp;

    private const FIXTURE = __DIR__ . '/Fixtures/ManagesLoops';

    /** @var list<string> */
    private const ARGUMENTS = ['-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--output-format=json'];

    /**
     * @return list<array{type: string, file: string, message: string}>
     */
    private function analyze(): array
    {
        $issues = [];

        foreach ($this->fixtureIssues(self::FIXTURE, self::ARGUMENTS) as $issue) {
            $issues[] = [
                'type' => (string) $issue['type'],
                'file' => \basename((string) $issue['file_name']),
                'message' => (string) $issue['message'],
            ];
        }

        return $issues;
    }

    /**
     * @param list<array{type: string, file: string, message: string}> $issues
     *
     * @return list<array{type: string, file: string, message: string}>
     */
    private function forFile(array $issues, string $file): array
    {
        return \array_values(\array_filter($issues, static fn(array $issue): bool => $issue['file'] === $file));
    }

    /**
     * A silent assertion on a template proves nothing if the template never compiled. Checks that
     * $template made it into the shadow manifest for this run (mirrors
     * NullableEchoTest::assertBladeAnalyzed()).
     */
    private function assertTemplateCompiled(string $template): void
    {
        $manifest = (string) \file_get_contents($this->fixtureShadows(self::FIXTURE, self::ARGUMENTS) . '/manifest.php');
        $templatePath = \realpath(self::FIXTURE . '/resources/views/' . $template);
        $this->assertIsString($templatePath, "{$template} does not exist on disk.");
        $this->assertStringContainsString(
            $templatePath,
            $manifest,
            "{$template} was never compiled into a shadow, so the silence below proves nothing.",
        );
    }

    /**
     * `non-iterable.blade.php` deterministically reports at least one issue (a genuinely
     * non-iterable `@foreach` argument), independent of $template — used as a fixed anchor across
     * every test to prove the shadow pipeline reached the analyzer at all this run, not just that
     * $template's own shadow exists.
     *
     * @param list<array{type: string, file: string, message: string}> $issues
     */
    private function assertPipelineReachedAnalyzer(array $issues): void
    {
        $this->assertNotSame([], $this->forFile($issues, 'non-iterable.blade.php'), \var_export($issues, true));
    }

    #[Test]
    public function loop_property_access_inside_a_plain_foreach_is_silent(): void
    {
        $issues = $this->analyze();

        $this->assertTemplateCompiled('foreach.blade.php');
        $this->assertPipelineReachedAnalyzer($issues);
        $this->assertSame([], $this->forFile($issues, 'foreach.blade.php'), \var_export($issues, true));
    }

    /**
     * The `Illuminate\Contracts\Pagination\Paginator` interface itself declares no `extends
     * \Traversable` in real Laravel (it exposes `items(): array` precisely because implementers
     * are not guaranteed iterable), so a variable typed to the bare contract still reports
     * RawObjectIteration/UnusedForeachValue on `@foreach` after this fix — that is correct
     * behavior, not a regression, and out of this stub's scope. What the fix removes is the
     * InvalidArgument that `addLoop()`'s old `\Countable|array` param produced for every such
     * paginator, since the contract does not extend Countable either.
     */
    #[Test]
    public function foreach_over_a_paginator_contract_no_longer_faults_addloop(): void
    {
        $issues = $this->analyze();
        $reported = $this->forFile($issues, 'paginator.blade.php');

        $this->assertTemplateCompiled('paginator.blade.php');
        $this->assertPipelineReachedAnalyzer($issues);
        $this->assertNotContains('InvalidArgument', \array_column($reported, 'type'), \var_export($issues, true));
    }

    #[Test]
    public function foreach_over_a_non_iterable_still_reports(): void
    {
        $issues = $this->analyze();
        $reported = $this->forFile($issues, 'non-iterable.blade.php');

        $this->assertTemplateCompiled('non-iterable.blade.php');
        $this->assertPipelineReachedAnalyzer($issues);
        $this->assertContains('InvalidIterator', \array_column($reported, 'type'), \var_export($issues, true));
    }

    /**
     * Real Laravel's getLastLoop() returns `(object) $last`, which IS a `\stdClass` instance, not
     * merely something shaped like one. A stub return type of plain `object{...}` (no class
     * identity) loses that fact: a userland `?\stdClass`-typed wrapper around the call then
     * reports LessSpecificReturnStatement/MoreSpecificReturnType, because Psalm cannot prove the
     * returned shape IS a `\stdClass`.
     */
    #[Test]
    public function a_stdclass_typed_wrapper_around_getlastloop_is_silent(): void
    {
        $issues = $this->analyze();

        $this->assertPipelineReachedAnalyzer($issues);
        $this->assertSame([], $this->forFile($issues, 'Source.php'), \var_export($issues, true));
    }

    /**
     * #1696: `$loop->parent` is typed `object|null` for the depth-1 ambient `$loop`, but inside a
     * nested `@foreach` the compiled `$loop = $__env->getLastLoop();` is only reached with a
     * live parent frame.
     *
     * @return iterable<string, array{string}>
     */
    public static function nestedLoopTemplates(): iterable
    {
        yield 'two levels' => ['nested.blade.php'];
        yield 'three levels, reads after an inner @endforeach' => ['nested-depth3.blade.php'];
        yield 'forelse inside forelse, read inside @empty' => ['nested-forelse.blade.php'];
        yield 'includer of a partial that reads the parent' => ['nested-include.blade.php'];
    }

    #[Test]
    #[DataProvider('nestedLoopTemplates')]
    public function loop_parent_is_non_null_inside_nested_loops(string $template): void
    {
        $issues = $this->analyze();

        $this->assertTemplateCompiled($template);
        $this->assertPipelineReachedAnalyzer($issues);
        $this->assertSame([], $this->forFile($issues, $template), \var_export($issues, true));
    }

    /**
     * @return iterable<string, array{string, string}> template, expected issue type
     */
    public static function stillNullableTemplates(): iterable
    {
        yield 'depth 1' => ['depth1.blade.php', 'PossiblyNullPropertyFetch'];
        yield 'one level above the nesting depth' => ['nested-too-deep.blade.php', 'PossiblyNullPropertyFetch'];
        yield 'loop nested only inside a top-level @for' => ['nested-in-for.blade.php', 'PossiblyNullPropertyFetch'];
        yield '@include d partial analyzed alone' => ['partials/row.blade.php', 'PossiblyNullPropertyFetch'];
        // The author's own assignment wins over the compiler's: the re-assert sits on Blade's assignment, not later.
        yield 'author reassigned $loop' => ['nested-author-loop.blade.php', 'NullPropertyFetch'];
    }

    #[Test]
    #[DataProvider('stillNullableTemplates')]
    public function loop_parent_stays_nullable_where_nothing_proves_a_parent(string $template, string $expectedType): void
    {
        $issues = $this->analyze();
        $reported = $this->forFile($issues, \basename($template));

        $this->assertTemplateCompiled($template);
        $this->assertPipelineReachedAnalyzer($issues);
        $this->assertContains($expectedType, \array_column($reported, 'type'), \var_export($issues, true));
    }

    /**
     * Accepted soundness gap (#1696): the nested `$loop` frame is asserted by a compiler-emitted
     * docblock, so a defensive guard on `$loop->parent` inside a nested loop is now provably
     * redundant to Psalm. Pinned so a future drop gate shows up as a diff here.
     */
    #[Test]
    public function defensive_parent_guards_in_a_nested_loop_report_as_redundant_known_limitation(): void
    {
        $issues = $this->analyze();
        $reported = $this->forFile($issues, 'nested-guard-known-limitation.blade.php');

        $this->assertTemplateCompiled('nested-guard-known-limitation.blade.php');
        $this->assertPipelineReachedAnalyzer($issues);
        $types = \array_column($reported, 'type');
        \sort($types);
        $this->assertSame(
            \array_merge(
                \array_fill(0, 2, 'DocblockTypeContradiction'),
                \array_fill(0, 5, 'RedundantConditionGivenDocblockType'),
            ),
            $types,
            \var_export($reported, true),
        );
    }
}
