<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use Illuminate\View\Compilers\BladeCompiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\Annotate\AnnotateRequest;

/**
 * The runs of the annotate pass that must write nothing, or must not widen what a normal run
 * reports: a control file this CLI did not write, `--dry-run`, and a project with Blade switched
 * off. What the writing run produces is pinned in {@see AnnotateEndToEndTest}.
 *
 * Three subprocesses in total; the decoy run doubles as the ordinary run (the environment gate
 * refuses it, so Psalm behaves as it does with no variable at all) and is the positive control for
 * the taint check.
 */
#[CoversClass(AnnotateRequest::class)]
#[Group('subprocess')]
final class AnnotateSafetyTest extends TestCase
{
    use RunsAnnotateFixture;

    #[Test]
    public function refuses_a_control_file_this_cli_did_not_write(): void
    {
        // A leaked PSALM_LARAVEL_BLADE_ANNOTATE (a stale shell, a .envrc, a CI export) must not turn
        // an ordinary psalm run into a codemod, nor overwrite whatever the variable happens to name.
        $run = $this->analyse('decoy');

        $this->assertSame(self::DECOY, $run['control'], 'the named file must be left intact');
        $this->assertTemplatesPristine($run['templates']);
    }

    #[Test]
    public function reports_the_diff_without_writing_under_dry_run(): void
    {
        $run = $this->analyse('dry-run');
        $result = $this->annotateResult('dry-run');

        $this->assertTemplatesPristine($run['templates']);
        $this->assertIsArray($result['changed'] ?? null);
        // 16 for the templates that need no include; the chains add 10 where `@includeIsolated` compiles
        // (pair 2, chain 3, opt 2, narrow 3), and only the 5 parents that read the name themselves
        // (pair-top, chain-top, cyc-a, narrow-top, narrow-isset-top) where it stays text.
        $this->assertCount(16 + (\method_exists(BladeCompiler::class, 'compileIncludeIsolated') ? 10 : 5), $result['changed']);
        $this->assertStringContainsString('+ * @var string $title', (string) ($result['diff'] ?? ''));
    }

    /**
     * The dry run prints the diff the writing run applies, includes typed along a chain included.
     * The writing run is a real one of its own over the same pristine templates, which are put back
     * afterwards for the cases that read the copy as the fixture wrote it.
     */
    #[Test]
    public function prints_under_dry_run_the_diff_a_real_run_writes(): void
    {
        $dry = (string) ($this->annotateResult('dry-run')['diff'] ?? '');
        $this->assertStringContainsString('+ * @var string $title', $dry);

        try {
            $written = \json_decode($this->analyseUncached('annotate')['control'], true);
        } finally {
            $this->restorePristineTemplates();
        }

        $this->assertIsArray($written);
        $this->assertSame($dry, $written['diff'] ?? null);

        if (\method_exists(BladeCompiler::class, 'compileIncludeIsolated')) {
            $this->assertStringContainsString('+ * @var \AnnotateFixture\Post $entry', $dry);
        }
    }

    #[Test]
    public function skips_taint_analysis_in_annotate_mode(): void
    {
        // The codemod reads call-site types only; the taint graph Psalm 7 builds by default is
        // pure cost there. The ordinary run is the positive control: the fixture has a taint flow.
        $this->assertStringContainsString('"type":"TaintedHtml"', $this->analyse('decoy')['output']);
        $this->assertStringNotContainsString('"type":"Tainted', $this->analyse('dry-run')['output']);
    }

    /**
     * The view-data checks follow the project's own Blade setting. The codemod switches Blade on for a
     * project that has it off, but that run must not start reporting view data: it lacks the view
     * finder an `@includeFirst` candidate is resolved through. `noteOmitted()` passes an unread key.
     */
    #[Test]
    public function a_run_with_blade_on_only_for_the_codemod_reports_no_view_data(): void
    {
        $this->assertSame(['UnusedViewData'], $this->viewDataIssues($this->analyse('dry-run')['output']));
        $this->assertSame([], $this->viewDataIssues($this->analyse('dry-run', 'psalm-blade-off.xml')['output']));
    }

    /** @return list<string> the view-data issue types a report contains */
    private function viewDataIssues(string $output): array
    {
        $decoded = \json_decode($output, true);
        $this->assertIsArray($decoded, $output);

        $types = [];

        foreach ($decoded as $issue) {
            $this->assertIsArray($issue);

            if (\in_array($issue['type'], ['MissingViewData', 'InvalidViewData', 'UnusedViewData'], true)) {
                $types[] = (string) $issue['type'];
            }
        }

        return $types;
    }
}
