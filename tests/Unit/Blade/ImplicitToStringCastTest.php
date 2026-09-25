<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins issue #1534: `e()`'s stub now accepts `\Stringable` (it has no type declaration in Laravel
 * source; `htmlspecialchars()` coerces at runtime), so `{{ $stringable }}` and `@php echo
 * e($stringable); @endphp` are both silent. Other `ImplicitToStringCast` sites (a `\Stringable`
 * passed to a plain function like `strlen()`, in a `@php` block or a directive argument) still
 * report: only `e()`'s own contract was widened, not the issue class.
 */
#[CoversNothing]
final class ImplicitToStringCastTest extends TestCase
{
    use AnalysesFixtureApp;

    private const FIXTURE = __DIR__ . '/Fixtures/ImplicitToString';

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
     * `php-strlen.blade.php` reporting at least one issue proves the shadow pipeline reached the
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
        $this->assertNotSame([], $this->forFile($issues, 'php-strlen.blade.php'), $manifest);
    }

    #[Test]
    public function an_escaped_echo_of_a_stringable_value_is_silent(): void
    {
        $issues = $this->analyze();

        $this->assertBladeAnalyzed($issues, 'esc-stringable.blade.php');
        $this->assertSame([], $this->forFile($issues, 'esc-stringable.blade.php'), \var_export($issues, true));
    }

    /**
     * `{!! !!}` compiles to a bare `echo`, which is exempt from `ImplicitToStringCast` in Psalm
     * core (echo/print are excluded from the argument check). Pins that pre-existing exemption as
     * a canary against a future Psalm upgrade changing it, independent of the `e()` widen.
     */
    #[Test]
    public function a_raw_echo_of_a_stringable_value_is_silent(): void
    {
        $issues = $this->analyze();

        $this->assertBladeAnalyzed($issues, 'raw-stringable.blade.php');
        $this->assertSame([], $this->forFile($issues, 'raw-stringable.blade.php'), \var_export($issues, true));
    }

    /**
     * Deliberate deviation from a literal reading of #1534 (which asked for `@php echo e($s);` to
     * keep reporting): the cast through `e()` is safe everywhere, not only at echo positions, so
     * widening its stub silences this call site too.
     */
    #[Test]
    public function an_explicit_e_call_in_a_php_block_is_silent(): void
    {
        $issues = $this->analyze();

        $this->assertBladeAnalyzed($issues, 'php-block.blade.php');
        $this->assertSame([], $this->forFile($issues, 'php-block.blade.php'), \var_export($issues, true));
    }

    #[Test]
    public function a_stringable_passed_to_a_plain_function_in_a_php_block_still_reports(): void
    {
        $issues = $this->analyze();
        $reported = $this->forFile($issues, 'php-strlen.blade.php');

        $this->assertCount(1, $reported, \var_export($issues, true));
        $this->assertSame('ImplicitToStringCast', $reported[0]['type'], \var_export($issues, true));
    }

    #[Test]
    public function a_stringable_passed_to_a_plain_function_in_a_directive_argument_still_reports(): void
    {
        $issues = $this->analyze();
        $reported = $this->forFile($issues, 'directive-arg.blade.php');

        $this->assertCount(1, $reported, \var_export($issues, true));
        $this->assertSame('ImplicitToStringCast', $reported[0]['type'], \var_export($issues, true));
    }
}
