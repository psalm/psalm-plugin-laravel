<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins issue #1535: `PossiblyInvalidArgument`/`PossiblyFalseArgument` on the argument of an echo
 * construct Blade itself synthesized (`{{ }}`, `{{{ }}}`, `{!! !!}`) is dropped, because the author
 * never wrote that call and their only way to silence it is a cast on every optional field. An
 * `e()`/`echo` the author DID write — inside `@php`, or nested in an echo — keeps reporting, and so
 * does an argument to any other callee and a wholly invalid one.
 *
 * A real `vendor/bin/psalm` run is the only way to pin it: the compiled echo forms, and the
 * template source the gate compares them against, only exist once the fixture's templates run
 * through the Blade shadow compiler.
 */
#[CoversNothing]
final class EchoUnionArgumentTest extends TestCase
{
    use AnalysesFixtureApp;

    private const FIXTURE = __DIR__ . '/Fixtures/EchoUnion';

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
     * A silent assertion on a template proves nothing if the template never compiled (or if Blade
     * analysis is off entirely — both cases leave every `.blade.php` path silent identically to the
     * intended fix). Checked from facts the run already produced: the manifest records every
     * template that made it through `compileAll()` this run, keyed by its real path, and
     * `php-e.blade.php` reporting at least one issue proves the shadow pipeline reached the
     * analyzer and remapped an issue back onto a `.blade.php` path.
     *
     * @param list<array{type: string, file: string, message: string}> $issues
     */
    private function assertBladeAnalyzed(array $issues, string $template): void
    {
        $manifest = (string) \file_get_contents($this->fixtureShadows(self::FIXTURE, self::ARGUMENTS) . '/manifest.php');
        $templatePath = \realpath(self::FIXTURE . '/resources/views/' . $template);
        // A missing template would make realpath() return false; cast to string that is '', and
        // assertStringContainsString() matches an empty needle against ANY manifest, so the guard
        // itself would pass vacuously. Pin the path's existence first.
        $this->assertIsString($templatePath, "{$template} does not exist on disk.");
        $this->assertStringContainsString(
            $templatePath,
            $manifest,
            "{$template} was never compiled into a shadow, so the silence below proves nothing.",
        );
        $this->assertNotSame([], $this->forFile($issues, 'php-e.blade.php'), $manifest);
    }

    /**
     * `{{ }}` compiles through `$echoFormat` and `{{{ }}}` through a hard-coded `e(`; both reach
     * the analyzer as the same `e(...)` call, so both are dropped by the same gate.
     */
    #[Test]
    public function a_compiler_generated_escaped_echo_of_a_union_is_silent(): void
    {
        $issues = $this->analyze();

        foreach (['esc-old.blade.php', 'esc-old-default.blade.php', 'triple-old.blade.php'] as $template) {
            $this->assertBladeAnalyzed($issues, $template);
            $this->assertSame([], $this->forFile($issues, $template), \var_export($issues, true));
        }
    }

    /**
     * `{!! !!}` compiles to a bare `echo`, which Psalm names `echo` in the message exactly like a
     * function callee — the family fires there too, and the gate covers it.
     */
    #[Test]
    public function a_compiler_generated_raw_echo_of_a_union_is_silent(): void
    {
        $issues = $this->analyze();

        $this->assertBladeAnalyzed($issues, 'raw-old.blade.php');
        $this->assertSame([], $this->forFile($issues, 'raw-old.blade.php'), \var_export($issues, true));
    }

    /** The `PossiblyFalseArgument` half of the family, from a `string|false` producer. */
    #[Test]
    public function a_compiler_generated_escaped_echo_of_a_falsable_value_is_silent(): void
    {
        $issues = $this->analyze();

        $this->assertBladeAnalyzed($issues, 'esc-false.blade.php');
        $this->assertSame([], $this->forFile($issues, 'esc-false.blade.php'), \var_export($issues, true));
    }

    /**
     * The load-bearing case: `@php echo e(old('k')); @endphp` and `{{ old('k') }}` compile to
     * BYTE-IDENTICAL shadow lines, so only looking for the sliced call in the raw template tells
     * them apart. Same for `@php echo old('k'); @endphp` against `{!! old('k') !!}`.
     */
    #[Test]
    public function an_authors_own_echo_inside_a_php_block_still_reports(): void
    {
        $issues = $this->analyze();

        foreach (['php-e.blade.php', 'php-echo.blade.php'] as $template) {
            $reported = $this->forFile($issues, $template);

            $this->assertCount(1, $reported, \var_export($issues, true));
            $this->assertSame('PossiblyInvalidArgument', $reported[0]['type'], \var_export($issues, true));
        }
    }

    /**
     * `{{ e(old('k')) }}` compiles to `echo e(e(old('k')))`. The outer `e` is the compiler's and
     * receives a `string`, so it never reports; the inner one is the author's own call and keeps
     * its issue.
     */
    #[Test]
    public function an_authors_own_escape_call_nested_in_an_echo_still_reports(): void
    {
        $issues = $this->analyze();
        $reported = $this->forFile($issues, 'nested-e.blade.php');

        $this->assertCount(1, $reported, \var_export($issues, true));
        $this->assertSame('PossiblyInvalidArgument', $reported[0]['type'], \var_export($issues, true));
    }

    /**
     * Blade rewrites raw text INSIDE an author's own expression, so "absent from the template" is
     * not by itself evidence that the compiler wrote the call. `@@foo` is unescaped to `@foo` by
     * `compileStatement()`, which runs before echos are compiled, and the component-class markers
     * are stripped from the finished output after `@php` blocks are restored. In both cases the
     * author's own `e(...)` call reaches the analyzer in a form their template does not contain.
     */
    #[Test]
    public function an_authors_own_escape_call_whose_argument_blade_rewrote_still_reports(): void
    {
        $issues = $this->analyze();

        foreach (['escaped-directive.blade.php', 'component-marker.blade.php'] as $template) {
            $types = \array_column($this->forFile($issues, $template), 'type');

            $this->assertContains('PossiblyInvalidArgument', $types, \var_export($issues, true));
        }
    }

    /**
     * The accepted cost of the guard above, pinned rather than left to drift: when Blade rewrote
     * the argument of a genuinely generated echo, the gate can no longer prove the callee is the
     * compiler's and keeps the issue. Noise on a rare shape, in exchange for never dropping a
     * rewritten author call. See the caveat in `ShadowIssueRelocator::isGeneratedEchoArgument()`.
     */
    #[Test]
    public function a_generated_echo_whose_argument_blade_rewrote_keeps_its_issue(): void
    {
        $issues = $this->analyze();
        $types = \array_column($this->forFile($issues, 'escaped-directive-generated.blade.php'), 'type');

        $this->assertContains('PossiblyInvalidArgument', $types, \var_export($issues, true));
    }

    /**
     * The gate is anchored on the enclosing callee, not on the union: the same value passed to a
     * user-written function inside an echo is a real finding. Asserted by presence rather than by
     * count — a `PossiblyInvalidCast` on the same expression rides along.
     */
    #[Test]
    public function an_argument_to_a_user_function_in_an_echo_still_reports(): void
    {
        $issues = $this->analyze();
        $types = \array_column($this->forFile($issues, 'user-fn.blade.php'), 'type');

        $this->assertContains('PossiblyInvalidArgument', $types, \var_export($issues, true));
    }

    /**
     * Only the two POSSIBLY-* classes are gated. A definite `array` in an echo position is a
     * runtime fatal in `htmlspecialchars()`, reports as `InvalidArgument`, and must keep reporting.
     */
    #[Test]
    public function a_definitely_invalid_echo_argument_still_reports(): void
    {
        $issues = $this->analyze();
        $reported = $this->forFile($issues, 'definite-array.blade.php');

        $this->assertCount(1, $reported, \var_export($issues, true));
        $this->assertSame('InvalidArgument', $reported[0]['type'], \var_export($issues, true));
    }
}
