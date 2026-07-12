<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Cli\Diagnose\Diagnostics;
use Psalm\LaravelPlugin\Cli\Diagnose\Report;
use Psalm\LaravelPlugin\Cli\Diagnose\TipsProvider;
use Psalm\LaravelPlugin\Cli\DiagnoseCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DiagnoseCommand::class)]
#[CoversClass(Diagnostics::class)]
#[CoversClass(Report::class)]
#[CoversClass(TipsProvider::class)]
final class DiagnoseCommandTest extends TestCase
{
    #[Test]
    public function fixture_report_includes_versions_and_boot_sections(): void
    {
        $tester = $this->testerFor($this->fixtureProvider($this->okReport()));

        $exit = $tester->execute([]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $exit, $display);
        $this->assertStringContainsString('Versions', $display);
        $this->assertStringContainsString('Boot mode', $display);
        $this->assertStringContainsString('Experimental issue enforcement', $display);
        $this->assertStringContainsString('disabled', $display);
        $this->assertStringNotContainsString('Hard failures', $display);
    }

    #[Test]
    public function experimental_enforcement_is_rendered_when_enabled(): void
    {
        $base = $this->okReport();
        $enabled = new Report(
            pluginVersion: $base->pluginVersion,
            psalmVersion: $base->psalmVersion,
            laravelVersion: $base->laravelVersion,
            phpRuntimeVersion: $base->phpRuntimeVersion,
            phpAnalysisVersion: $base->phpAnalysisVersion,
            phpAnalysisSource: $base->phpAnalysisSource,
            experimentalIssueEnforcement: true,
            bootMode: $base->bootMode,
            bootPath: $base->bootPath,
            bootstrapErrors: $base->bootstrapErrors,
            hardFailures: $base->hardFailures,
            loadedProviders: $base->loadedProviders,
        );

        $tester = $this->testerFor($this->fixtureProvider($enabled));
        $tester->execute([]);

        $this->assertStringContainsString('Experimental issue enforcement', $tester->getDisplay());
        $this->assertStringContainsString('enabled', $tester->getDisplay());
    }

    #[Test]
    public function diagnostics_reads_psalm_xml_and_falls_back_to_psalm_xml_dist(): void
    {
        $diagnostics = new class extends Diagnostics {
            public function experimentalIssueEnforcementFor(string $projectRoot): bool
            {
                return $this->resolveExperimentalIssueEnforcement($projectRoot);
            }
        };

        $fixtures = __DIR__ . '/Fixtures/Diagnostics';

        $this->assertTrue($diagnostics->experimentalIssueEnforcementFor($fixtures . '/PsalmXml'));
        $this->assertFalse($diagnostics->experimentalIssueEnforcementFor($fixtures . '/PsalmXmlDist'));
    }

    #[Test]
    public function exits_failure_when_a_hard_failure_is_present(): void
    {
        $base = $this->okReport();
        $failing = new Report(
            pluginVersion: $base->pluginVersion,
            psalmVersion: $base->psalmVersion,
            laravelVersion: $base->laravelVersion,
            phpRuntimeVersion: $base->phpRuntimeVersion,
            phpAnalysisVersion: $base->phpAnalysisVersion,
            phpAnalysisSource: $base->phpAnalysisSource,
            experimentalIssueEnforcement: $base->experimentalIssueEnforcement,
            bootMode: null,
            bootPath: null,
            bootstrapErrors: ['synthetic'],
            hardFailures: ['Application boot failed: synthetic'],
            loadedProviders: [],
        );

        $tester = $this->testerFor($this->fixtureProvider($failing));

        $exit = $tester->execute([]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('Hard failures', $display);
        $this->assertStringContainsString('Application boot failed: synthetic', $display);
    }

    #[Test]
    public function bootstrap_warnings_render_under_boot_section(): void
    {
        $base = $this->okReport();
        $warned = new Report(
            pluginVersion: $base->pluginVersion,
            psalmVersion: $base->psalmVersion,
            laravelVersion: $base->laravelVersion,
            phpRuntimeVersion: $base->phpRuntimeVersion,
            phpAnalysisVersion: $base->phpAnalysisVersion,
            phpAnalysisSource: $base->phpAnalysisSource,
            experimentalIssueEnforcement: $base->experimentalIssueEnforcement,
            bootMode: $base->bootMode,
            bootPath: $base->bootPath,
            bootstrapErrors: ['Call to a member function bar() on null in config/app.php:42'],
            hardFailures: [],
            loadedProviders: $base->loadedProviders,
        );

        $tester = $this->testerFor($this->fixtureProvider($warned));

        $exit = $tester->execute([]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $exit, $display);
        // SymfonyStyle wraps `$io->warning` blocks to terminal width, so the
        // message may span two lines. Assert each fragment separately rather
        // than the joined string.
        $this->assertStringContainsString('Bootstrap warning: Call to a member function bar()', $display);
        $this->assertStringContainsString('config/app.php:42', $display);
    }

    #[Test]
    public function tips_render_when_tips_flag_is_set(): void
    {
        $tipsProvider = $this->stubTipsProvider(['Install ext-igbinary (>=2.0.5) for faster Psalm cache serialization.']);
        $tester = $this->testerFor($this->fixtureProvider($this->okReport()), $tipsProvider);

        $exit = $tester->execute(['--tips' => true]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $exit, $display);
        $this->assertStringContainsString('Tips', $display);
        $this->assertStringContainsString('Install ext-igbinary (>=2.0.5)', $display);
    }

    #[Test]
    public function tips_are_suppressed_by_default(): void
    {
        $tipsProvider = $this->stubTipsProvider(['should not appear']);
        $tester = $this->testerFor($this->fixtureProvider($this->okReport()), $tipsProvider);

        $exit = $tester->execute([]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $exit, $display);
        $this->assertStringNotContainsString('Tips', $display);
        $this->assertStringNotContainsString('should not appear', $display);
    }

    #[Test]
    public function no_tips_flag_suppresses_tips_section(): void
    {
        $tipsProvider = $this->stubTipsProvider(['should not appear']);
        $tester = $this->testerFor($this->fixtureProvider($this->okReport()), $tipsProvider);

        $exit = $tester->execute(['--no-tips' => true]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $exit, $display);
        $this->assertStringNotContainsString('Tips', $display);
        $this->assertStringNotContainsString('should not appear', $display);
    }

    #[Test]
    public function provider_count_renders_by_default_without_the_full_list(): void
    {
        $tester = $this->testerFor($this->fixtureProvider($this->okReport()));

        $exit = $tester->execute([]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $exit, $display);
        $this->assertStringContainsString('Providers', $display);
        $this->assertStringContainsString('2 (use --providers to list them)', $display);
        $this->assertStringNotContainsString('Illuminate\\Auth\\AuthServiceProvider', $display);
    }

    #[Test]
    public function providers_flag_lists_every_provider(): void
    {
        $tester = $this->testerFor($this->fixtureProvider($this->okReport()));

        $exit = $tester->execute(['--providers' => true]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $exit, $display);
        $this->assertStringContainsString('Illuminate\\Auth\\AuthServiceProvider', $display);
        $this->assertStringContainsString('Illuminate\\Database\\DatabaseServiceProvider', $display);
    }

    #[Test]
    public function real_diagnostics_collect_returns_well_formed_report(): void
    {
        $report = (new Diagnostics())->collect();

        $this->assertNotEmpty($report->phpRuntimeVersion);
        $this->assertNotEmpty($report->phpAnalysisVersion);
        $this->assertContains($report->phpAnalysisSource, ['runtime', 'psalm.xml']);
        $this->assertContains($report->bootMode, ['bootstrap', 'testbench_fallback', null]);
        // A successful boot registers Laravel's core providers; assert the list is
        // populated and sorted so the diagnose output is deterministic.
        $this->assertNotEmpty($report->loadedProviders);
        $sorted = $report->loadedProviders;
        \sort($sorted);
        $this->assertSame($sorted, $report->loadedProviders);
    }


    private function testerFor(Diagnostics $diagnostics, ?TipsProvider $tipsProvider = null): CommandTester
    {
        $command = new DiagnoseCommand($diagnostics, $tipsProvider);
        $app = new Application();
        $app->addCommand($command);

        return new CommandTester($app->find('diagnose'));
    }

    private function fixtureProvider(Report $report): Diagnostics
    {
        return new class ($report) extends Diagnostics {
            public function __construct(private readonly Report $report) {}

            #[\Override]
            public function collect(): Report
            {
                return $this->report;
            }
        };
    }

    /**
     * @param list<string> $tips
     */
    private function stubTipsProvider(array $tips): TipsProvider
    {
        return new class ($tips) extends TipsProvider {
            /** @param list<string> $tips */
            public function __construct(private readonly array $tips) {}

            #[\Override]
            public function collect(): array
            {
                return $this->tips;
            }
        };
    }

    private function okReport(): Report
    {
        return new Report(
            pluginVersion: '4.0.0',
            psalmVersion: '7.0.0-beta19',
            laravelVersion: '13.9.0',
            phpRuntimeVersion: '8.4.0',
            phpAnalysisVersion: '8.4.0',
            phpAnalysisSource: 'runtime',
            experimentalIssueEnforcement: false,
            bootMode: 'bootstrap',
            bootPath: '/app/bootstrap/app.php',
            bootstrapErrors: [],
            hardFailures: [],
            loadedProviders: [
                'Illuminate\\Auth\\AuthServiceProvider',
                'Illuminate\\Database\\DatabaseServiceProvider',
            ],
        );
    }
}
