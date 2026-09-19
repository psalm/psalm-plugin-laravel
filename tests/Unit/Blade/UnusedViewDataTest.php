<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\ReadSetResolver;
use Psalm\LaravelPlugin\Handlers\Views\ViewContractHandler;
use Symfony\Component\Process\Process;

/**
 * End-to-end proof that a data key no template in the include chain reads is reported. A real
 * `vendor/bin/psalm` run is the only way to pin it: the read set only exists after the Blade
 * bootstrap has compiled the fixture's templates, and the check reads the data keys Psalm resolves
 * at the call site during analysis.
 *
 * phpt type tests cannot cover this, for the same reason {@see ViewContractValidationTest} gives:
 * they analyze against Testbench's view root inside `vendor/`, and no `tests/Type/psalm-*.xml`
 * config enables Blade.
 */
#[CoversClass(ViewContractHandler::class)]
#[CoversClass(ReadSetResolver::class)]
final class UnusedViewDataTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/Fixtures/UnusedViewData';

    private const SHADOW_DIR = self::FIXTURE . '/.cache/blade-shadows';

    private const UNUSED = 'UnusedViewData';

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
     * UnusedViewData only, keyed by the fixture file that caused it — the fixture reports plenty of
     * unrelated issues at errorLevel 1 and the shadows add more.
     *
     * @return list<array{file: string, message: string}>
     */
    private function unusedDataIssues(string $config): array
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

            if ((string) $issue['type'] !== self::UNUSED) {
                continue;
            }

            $issues[] = [
                'file' => \basename((string) $issue['file_name']),
                'message' => (string) $issue['message'],
            ];
        }

        return $issues;
    }

    /**
     * @param list<array{file: string, message: string}> $issues
     *
     * @return list<array{file: string, message: string}>
     */
    private function forFile(array $issues, string $file): array
    {
        return \array_values(\array_filter($issues, static fn(array $issue): bool => $issue['file'] === $file));
    }

    /**
     * @param list<array{file: string, message: string}> $issues
     */
    private function assertReportsOnly(array $issues, string $file, string $key): void
    {
        $reported = $this->forFile($issues, $file);

        $this->assertCount(1, $reported, \var_export($issues, true));
        $this->assertStringContainsString("'{$key}'", $reported[0]['message']);
    }

    #[Test]
    public function a_passed_key_the_template_never_reads_is_reported(): void
    {
        $this->assertReportsOnly($this->unusedDataIssues('psalm.xml'), 'UnreadKey.php', 'orphan');
    }

    #[Test]
    public function a_key_read_only_inside_a_literally_named_include_is_not_reported(): void
    {
        // 'deep' is read by the included partial, never by the host: only 'orphan' is left over.
        $this->assertReportsOnly($this->unusedDataIssues('psalm.xml'), 'IncludeChain.php', 'orphan');
    }

    #[Test]
    public function a_key_read_two_includes_deep_is_not_reported(): void
    {
        $issues = $this->unusedDataIssues('psalm.xml');

        $this->assertSame([], $this->forFile($issues, 'DeepIncludeChain.php'), \var_export($issues, true));
    }

    #[Test]
    public function an_include_cycle_terminates_and_still_reports(): void
    {
        $this->assertReportsOnly($this->unusedDataIssues('psalm.xml'), 'IncludeCycle.php', 'orphan');
    }

    /** Each of these pins a different decline gate, with everything else about the call held equal. */
    #[Test]
    public function every_decline_gate_stays_silent(): void
    {
        $issues = $this->unusedDataIssues('psalm.xml');

        // An `@include($name)` leaves the chain's read set a lower bound.
        $this->assertSame([], $this->forFile($issues, 'DynamicInclude.php'), \var_export($issues, true));
        // `@props` compiles to `$$__key` writes, which make the read set unknowable.
        $this->assertSame([], $this->forFile($issues, 'PropsPanel.php'), \var_export($issues, true));
        // So does `@aware`, via `$$__consumeVariable`.
        $this->assertSame([], $this->forFile($issues, 'AwareChild.php'), \var_export($issues, true));
        // A shadow the plugin cannot parse says nothing about what the template reads.
        $this->assertSame([], $this->forFile($issues, 'BrokenPhp.php'), \var_export($issues, true));
    }

    /**
     * Blade injects these itself, so a call site passing one is never wrong about the template — the
     * read-set extraction strips them, which is exactly why the emit side has to re-check them.
     */
    #[Test]
    public function an_ambient_key_is_never_reported(): void
    {
        $issues = $this->unusedDataIssues('psalm.xml');

        $this->assertSame([], $this->forFile($issues, 'AmbientKeys.php'), \var_export($issues, true));
    }

    /** A declared key is part of the template's contract whether or not its body reads it. */
    #[Test]
    public function a_declared_key_is_not_reported_even_when_unread(): void
    {
        $issues = $this->unusedDataIssues('psalm.xml');

        $this->assertSame([], $this->forFile($issues, 'DeclaredButUnread.php'), \var_export($issues, true));
    }

    /**
     * Loop aliases are dropped from the read set template-wide (a documented ContractParser gap), so
     * they are added back for this rule: over-counting a name as read is the safe direction.
     */
    #[Test]
    public function a_loop_alias_name_is_treated_as_read(): void
    {
        $issues = $this->unusedDataIssues('psalm.xml');

        $this->assertSame([], $this->forFile($issues, 'LoopAlias.php'), \var_export($issues, true));
    }

    /**
     * `@includeIsolated` and `@each` render their template with a fresh scope, so neither may launder
     * a key into the caller's read set.
     */
    #[Test]
    public function a_scope_isolating_directive_does_not_launder_its_read_set(): void
    {
        $issues = $this->unusedDataIssues('psalm.xml');

        $this->assertReportsOnly($issues, 'IsolatedInclude.php', 'secret');
        $this->assertReportsOnly($issues, 'EachHost.php', 'cell');
    }

    #[Test]
    public function a_suppression_in_the_calling_file_silences_it(): void
    {
        $issues = $this->unusedDataIssues('psalm.xml');

        $this->assertSame([], $this->forFile($issues, 'Suppressed.php'), \var_export($issues, true));
    }

    #[Test]
    public function the_check_is_off_unless_the_config_flag_opts_in(): void
    {
        $this->assertSame([], $this->unusedDataIssues('psalm-unused-data-off.xml'));
    }

    /**
     * A shadow cache warmed while the flag was off stores no read set and no data includes for any
     * template (neither pass ran). Flipping the flag on against that SAME cache must recompile each
     * template once, or every entry stays "fresh" with an unknowable read set forever and the rule
     * silently reports nothing.
     */
    #[Test]
    public function a_cache_warmed_with_the_flag_off_is_recompiled_once_the_flag_turns_on(): void
    {
        $this->unusedDataIssues('psalm-unused-data-off.xml');

        // Same fixture, same cache directory, flag now on, deliberately without deleting the cache.
        $this->assertReportsOnly($this->unusedDataIssues('psalm.xml'), 'UnreadKey.php', 'orphan');
    }
}
