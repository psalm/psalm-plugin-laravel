<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Cli\Diagnose\Diagnostics;
use Psalm\LaravelPlugin\Cli\Diagnose\Report;
use Psalm\LaravelPlugin\Cli\Diagnose\TextRenderer;
use Psalm\LaravelPlugin\Cli\DiagnoseCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DiagnoseCommand::class)]
#[CoversClass(Diagnostics::class)]
#[CoversClass(Report::class)]
#[CoversClass(TextRenderer::class)]
final class DiagnoseCommandTest extends TestCase
{
    #[Test]
    public function fixture_report_includes_versions_and_boot_sections(): void
    {
        $tester = $this->testerFor($this->fixtureProvider($this->okReport()));

        $exit = $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exit, $display);
        self::assertStringContainsString('[Versions]', $display);
        self::assertStringContainsString('[Boot mode (#766)]', $display);
        self::assertStringNotContainsString('[Hard failures]', $display);
    }

    #[Test]
    public function exits_failure_when_a_hard_failure_is_present(): void
    {
        $base = $this->okReport();
        $failing = new Report(
            pluginVersion: $base->pluginVersion,
            laravelVersion: $base->laravelVersion,
            psalmVersion: $base->psalmVersion,
            phpVersion: $base->phpVersion,
            bootMode: null,
            bootPath: null,
            bootError: 'synthetic',
            hardFailures: ['Application boot failed: synthetic'],
        );

        $tester = $this->testerFor($this->fixtureProvider($failing));

        $exit = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('[Hard failures]', $tester->getDisplay());
        self::assertStringContainsString('Application boot failed: synthetic', $tester->getDisplay());
    }

    #[Test]
    public function real_diagnostics_collect_returns_well_formed_report(): void
    {
        $report = (new Diagnostics())->collect();

        self::assertNotEmpty($report->phpVersion);
        self::assertContains($report->bootMode, ['user_kernel', 'vendor_bootstrap', 'testbench_fallback', null]);
    }

    private function testerFor(Diagnostics $diagnostics): CommandTester
    {
        $command = new DiagnoseCommand($diagnostics);
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

    private function okReport(): Report
    {
        return new Report(
            pluginVersion: '4.0.0',
            laravelVersion: '13.9.0',
            psalmVersion: '7.0.0-beta19',
            phpVersion: '8.4.0',
            bootMode: 'user_kernel',
            bootPath: '/app/bootstrap/app.php',
            bootError: null,
            hardFailures: [],
        );
    }
}
