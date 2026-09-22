<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Pins issue #1533: `{{ }}` and `{!! !!}` on a nullable value are silent in Blade echo positions
 * (the `e()` stub's `@param` already allows `null`, and bare `echo` of a nullable is silent in
 * Psalm core), while a nullable receiver further along a chain still reports. A real
 * `vendor/bin/psalm` run is the only way to pin it: the compiled echo forms only exist once the
 * fixture's templates run through the Blade shadow compiler.
 */
#[CoversNothing]
final class NullableEchoTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/Fixtures/NullableEcho';

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
     * A silent assertion on a template proves nothing if the template never compiled (or if Blade
     * analysis is off entirely — both cases leave every `.blade.php` path silent identically to the
     * intended fix). Checked from facts the run already produced: the manifest records every
     * template that made it through `compileAll()` this run, keyed by its real path, and
     * `chain-kept.blade.php` reporting at least one issue proves the shadow pipeline reached the
     * analyzer and remapped an issue back onto a `.blade.php` path.
     *
     * @param list<array{type: string, file: string, message: string}> $issues
     */
    private function assertBladeAnalyzed(array $issues, string $template): void
    {
        $manifest = (string) \file_get_contents(self::SHADOW_DIR . '/manifest.php');
        $this->assertStringContainsString(
            (string) \realpath(self::FIXTURE . '/resources/views/' . $template),
            $manifest,
            "{$template} was never compiled into a shadow, so the silence below proves nothing.",
        );
        $this->assertNotSame([], $this->forFile($issues, 'chain-kept.blade.php'), $manifest);
    }

    #[Test]
    public function an_escaped_echo_of_a_nullable_value_is_silent(): void
    {
        $issues = $this->analyze();

        $this->assertBladeAnalyzed($issues, 'esc-nullable.blade.php');
        $this->assertSame([], $this->forFile($issues, 'esc-nullable.blade.php'), \var_export($issues, true));
    }

    /**
     * Bare `echo` of a nullable is silent in Psalm core, not something the plugin enforces. There
     * is no plugin-side gate to mutate for this half; it pins the underlying Psalm behavior as a
     * canary against a future Psalm upgrade changing it.
     */
    #[Test]
    public function a_raw_echo_of_a_nullable_value_is_silent(): void
    {
        $issues = $this->analyze();

        $this->assertBladeAnalyzed($issues, 'raw-nullable.blade.php');
        $this->assertSame([], $this->forFile($issues, 'raw-nullable.blade.php'), \var_export($issues, true));
    }

    #[Test]
    public function a_nullable_receiver_further_along_a_chain_still_reports(): void
    {
        $issues = $this->analyze();
        $reported = $this->forFile($issues, 'chain-kept.blade.php');

        $this->assertCount(2, $reported, \var_export($issues, true));
        $types = \array_column($reported, 'type');
        $this->assertContains('PossiblyNullPropertyFetch', $types, \var_export($issues, true));
        $this->assertContains('PossiblyNullReference', $types, \var_export($issues, true));
    }
}
