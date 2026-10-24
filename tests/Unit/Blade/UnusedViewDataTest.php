<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\ReadSetResolver;
use Psalm\LaravelPlugin\Handlers\Views\ViewContractHandler;

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
#[Group('subprocess')]
final class UnusedViewDataTest extends TestCase
{
    use AnalysesFixtureApp;

    private const FIXTURE = __DIR__ . '/Fixtures/UnusedViewData';

    private const UNUSED = 'UnusedViewData';

    /**
     * The command a case's `$config` resolves to. Cases naming the same config share one run.
     * `--show-info` keeps the `info` default of this experimental issue in the report.
     *
     * @return list<string>
     */
    private function arguments(string $config): array
    {
        return ['-c', $config, '--no-cache', '--threads=1', '--no-progress', '--output-format=json', '--show-info=true'];
    }

    /**
     * UnusedViewData only, keyed by the fixture file that caused it — the fixture reports plenty of
     * unrelated issues at errorLevel 1 and the shadows add more.
     *
     * @return list<array{file: string, message: string}>
     */
    private function unusedDataIssues(string $config): array
    {
        return $this->project($this->fixtureIssues(self::FIXTURE, $this->arguments($config)));
    }

    /**
     * @param list<array<string, mixed>> $issues
     *
     * @return list<array{file: string, message: string}>
     */
    private function project(array $issues): array
    {
        $projected = [];

        foreach ($issues as $issue) {
            if ((string) $issue['type'] !== self::UNUSED) {
                continue;
            }

            $projected[] = [
                'file' => \basename((string) $issue['file_name']),
                'message' => (string) $issue['message'],
            ];
        }

        return $projected;
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
        $issues = $this->unusedDataIssues('psalm.xml');
        $this->assertReportsOnly($issues, 'UnreadKey.php', 'orphan');

        $this->assertSame(
            "View 'simple' never reads \$orphan, but this call passes 'orphan'. "
            . 'Remove the key, or declare it with @var if the template needs it.',
            $this->forFile($issues, 'UnreadKey.php')[0]['message'],
        );
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
     * A raw docblock the template writes in its own `<?php` block. Asserting it next to the control
     * above is what distinguishes "the declaration is read" from "this template happens to be
     * silent for an unrelated reason", and `UnreadKey.php` above proves the rule still fires for a
     * key declared nowhere.
     */
    #[Test]
    public function a_key_declared_by_a_raw_php_docblock_is_not_reported(): void
    {
        $issues = $this->unusedDataIssues('psalm.xml');

        $this->assertSame([], $this->forFile($issues, 'RawDeclaredButUnread.php'), \var_export($issues, true));
    }

    /**
     * The same rule one level down the include chain: "declares" has to count as consumed wherever a
     * contract is declared, or the check contradicts its own semantics for a partial that states its
     * interface with a `@var` docblock and leaves the body to a later revision.
     */
    #[Test]
    public function a_key_declared_by_an_included_template_is_not_reported(): void
    {
        $issues = $this->unusedDataIssues('psalm.xml');

        $this->assertSame([], $this->forFile($issues, 'DeclaredInInclude.php'), \var_export($issues, true));
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

    /** An experimental issue: `info` under a bare `<blade />`, `error` under `<experimental value="true" />`. */
    #[Test]
    public function the_check_runs_under_bare_blade_and_only_its_severity_follows_experimental(): void
    {
        $enforced = $this->unusedDataSeverities('psalm.xml');
        $advisory = $this->unusedDataSeverities('psalm-blade-only.xml');

        $this->assertNotSame([], $enforced);
        $this->assertSame(['error'], \array_values(\array_unique($enforced)));
        $this->assertSame(['info'], \array_values(\array_unique($advisory)));
        $this->assertSame(\array_keys($enforced), \array_keys($advisory));
    }

    /**
     * @return array<string, string> one entry per UnusedViewData issue, its identity => its severity
     */
    private function unusedDataSeverities(string $config): array
    {
        $severities = [];

        foreach ($this->fixtureIssues(self::FIXTURE, $this->arguments($config)) as $issue) {
            if ($issue['type'] === self::UNUSED) {
                $key = \implode(':', [\basename((string) $issue['file_name']), (string) $issue['line_from'], (string) $issue['message']]);
                $severities[$key] = (string) $issue['severity'];
            }
        }

        return $severities;
    }
}
