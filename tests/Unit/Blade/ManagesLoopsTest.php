<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

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
final class ManagesLoopsTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/Fixtures/ManagesLoops';

    private const SHADOW_DIR = self::FIXTURE . '/.cache/blade-shadows';

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
     * @return list<array{type: string, file: string, message: string}>
     */
    private function analyze(): array
    {
        $psalmBinary = \dirname(__DIR__, 3) . '/vendor/bin/psalm';
        $this->assertFileExists($psalmBinary, 'Psalm binary not found — run composer install.');

        $process = new Process(
            [\PHP_BINARY, $psalmBinary, '-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--output-format=json'],
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
        $manifest = (string) \file_get_contents(self::SHADOW_DIR . '/manifest.php');
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

        $this->assertTemplateCompiled('non-iterable.blade.php');
        $this->assertPipelineReachedAnalyzer($issues);
    }
}
