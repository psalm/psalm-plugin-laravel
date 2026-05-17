<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Cli\Diagnose\Diagnostics;
use Psalm\LaravelPlugin\Cli\Diagnose\TextRenderer;
use Psalm\LaravelPlugin\Cli\DiagnoseCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DiagnoseCommand::class)]
#[CoversClass(Diagnostics::class)]
#[CoversClass(TextRenderer::class)]
final class DiagnoseCommandTest extends TestCase
{
    #[Test]
    public function fixture_report_includes_versions_and_boot_sections(): void
    {
        $tester = $this->testerFor($this->fixtureProvider($this->okReport()));

        $exit = $tester->execute([]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $exit, $display);
        $this->assertStringContainsString('[Versions]', $display);
        $this->assertStringContainsString('[Boot mode (#766)]', $display);
        $this->assertStringNotContainsString('[Hard failures]', $display);
    }

    #[Test]
    public function exits_failure_when_a_hard_failure_is_present(): void
    {
        $report = $this->okReport();
        $report['hard_failures'] = ['Application boot failed: synthetic'];
        $tester = $this->testerFor($this->fixtureProvider($report));

        $exit = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('[Hard failures]', $tester->getDisplay());
        $this->assertStringContainsString('Application boot failed: synthetic', $tester->getDisplay());
    }

    #[Test]
    public function real_diagnostics_collect_returns_well_formed_report(): void
    {
        $report = (new Diagnostics())->collect();

        $this->assertNotEmpty($report['versions']['php']);
        $this->assertContains($report['boot']['mode'], ['user_kernel', 'vendor_bootstrap', 'testbench_fallback', null]);
    }

    private function testerFor(Diagnostics $diagnostics): CommandTester
    {
        $command = new DiagnoseCommand($diagnostics);
        $app = new Application();
        $app->addCommand($command);

        return new CommandTester($app->find('diagnose'));
    }

    /**
     * @param array<string, mixed> $report
     */
    private function fixtureProvider(array $report): Diagnostics
    {
        return new class ($report) extends Diagnostics {
            /** @param array<string, mixed> $report */
            public function __construct(private readonly array $report) {}

            #[\Override]
            public function collect(): array
            {
                /** @psalm-suppress InvalidReturnStatement */
                return $this->report;
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function okReport(): array
    {
        return [
            'versions' => [
                'plugin' => '4.0.0',
                'laravel' => '13.9.0',
                'psalm' => '7.0.0-beta19',
                'php' => '8.4.0',
            ],
            'boot' => [
                'mode' => 'user_kernel',
                'description' => 'user kernel (real bootstrap/app.php discovered)',
                'path' => '/app/bootstrap/app.php',
                'error' => null,
            ],
            'hard_failures' => [],
        ];
    }
}
