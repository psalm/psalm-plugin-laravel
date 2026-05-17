<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Cli\Diagnose\Diagnostics;
use Psalm\LaravelPlugin\Cli\Diagnose\JsonRenderer;
use Psalm\LaravelPlugin\Cli\Diagnose\MarkdownRenderer;
use Psalm\LaravelPlugin\Cli\Diagnose\TextRenderer;
use Psalm\LaravelPlugin\Cli\DiagnoseCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DiagnoseCommand::class)]
#[CoversClass(Diagnostics::class)]
#[CoversClass(TextRenderer::class)]
#[CoversClass(JsonRenderer::class)]
#[CoversClass(MarkdownRenderer::class)]
final class DiagnoseCommandTest extends TestCase
{
    #[Test]
    public function fixture_report_text_format_includes_all_section_headers(): void
    {
        $tester = $this->testerFor($this->fixtureProvider($this->okReport()));

        $exit = $tester->execute([]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $exit, $display);
        $this->assertStringContainsString('[Versions]', $display);
        $this->assertStringContainsString('[Boot mode (#766)]', $display);
        $this->assertStringContainsString('[Stub loading]', $display);
        $this->assertStringContainsString('[Integration stubs]', $display);
        $this->assertStringContainsString('[Handlers]', $display);
        $this->assertStringContainsString('[Schema parsing]', $display);
        $this->assertStringNotContainsString('[Hard failures]', $display);
    }

    #[Test]
    public function fixture_report_json_format_is_valid_json_with_expected_keys(): void
    {
        $tester = $this->testerFor($this->fixtureProvider($this->okReport()));

        $tester->execute(['--format' => 'json']);
        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('versions', $decoded);
        $this->assertArrayHasKey('boot', $decoded);
        $this->assertArrayHasKey('stubs', $decoded);
        $this->assertArrayHasKey('integrations', $decoded);
        $this->assertArrayHasKey('handlers', $decoded);
        $this->assertArrayHasKey('schema', $decoded);
        $this->assertArrayHasKey('hard_failures', $decoded);
    }

    #[Test]
    public function markdown_format_renders_github_compatible_tables(): void
    {
        $tester = $this->testerFor($this->fixtureProvider($this->okReport()));

        $tester->execute(['--format' => 'markdown']);

        $display = $tester->getDisplay();

        $this->assertStringContainsString('## psalm-plugin-laravel diagnose', $display);
        $this->assertStringContainsString('### Versions', $display);
        $this->assertStringContainsString('| --- | --- |', $display);
        $this->assertStringContainsString('### Schema parsing', $display);
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
    public function rejects_unknown_format(): void
    {
        $tester = $this->testerFor($this->fixtureProvider($this->okReport()));

        $exit = $tester->execute(['--format' => 'xml']);

        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString("Unknown --format 'xml'", $tester->getDisplay());
    }

    #[Test]
    public function real_diagnostics_collect_returns_well_formed_report(): void
    {
        $report = (new Diagnostics())->collect();

        $this->assertNotEmpty($report['versions']['php']);
        $this->assertContains($report['boot']['mode'], ['user_kernel', 'vendor_bootstrap', 'testbench_fallback', null]);
        $this->assertNotEmpty($report['stubs'], 'common/ stub dir must always resolve');
        $this->assertGreaterThan(0, $report['handlers']['total']);
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
            'stubs' => [
                ['dir' => '/stubs/common', 'reason' => 'common (always loaded)', 'file_count' => 100],
            ],
            'integrations' => [
                [
                    'package' => 'nesbot/carbon',
                    'installed' => true,
                    'version' => '3.0.0',
                    'satisfies' => true,
                    'constraint' => null,
                    'note' => 'CarbonStubProvider registers stubs.',
                ],
            ],
            'handlers' => ['categories' => ['Application' => 2, 'Eloquent' => 25], 'total' => 27],
            'schema' => [
                'state' => 'cold',
                'migration_dirs' => ['/app/database/migrations'],
                'migration_file_count' => 5,
                'tables_parsed' => null,
            ],
            'hard_failures' => [],
        ];
    }
}
