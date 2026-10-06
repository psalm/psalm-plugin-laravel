<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Cli\Diagnose\Diagnostics;
use Psalm\LaravelPlugin\Cli\Diagnose\PluginSettings;
use Psalm\LaravelPlugin\Cli\Diagnose\Report;
use Psalm\LaravelPlugin\Cli\Diagnose\TipsProvider;
use Psalm\LaravelPlugin\Cli\DiagnoseCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DiagnoseCommand::class)]
#[CoversClass(Diagnostics::class)]
#[CoversClass(PluginSettings::class)]
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
        $this->assertStringNotContainsString('Hard failures', $display);
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
            bootMode: null,
            bootPath: null,
            bootstrapErrors: ['synthetic'],
            hardFailures: ['Application boot failed: synthetic'],
            loadedProviders: [],
            pluginSettings: [],
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
            bootMode: $base->bootMode,
            bootPath: $base->bootPath,
            bootstrapErrors: ['Call to a member function bar() on null in config/app.php:42'],
            hardFailures: [],
            loadedProviders: $base->loadedProviders,
            pluginSettings: [],
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
    public function plugin_settings_render_with_their_value_and_source(): void
    {
        $tester = $this->testerFor($this->fixtureProvider($this->okReport()));

        $exit = $tester->execute([]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $exit, $display);
        $this->assertMatchesRegularExpression('/Plugin settings\n\s+blade\s+false\s+\(cli\)/', $display);
        $this->assertMatchesRegularExpression('/findUnregisteredRouteNames\s+true\s+\(derived \(experimental\)\)/', $display);
    }

    /**
     * @param list<string> $argv
     */
    #[Test]
    #[DataProvider('bladeFlagOrders')]
    public function the_last_of_a_blade_flag_and_plugin_option_wins(array $argv, string $expected): void
    {
        $tester = $this->realTester(['psalm-laravel', 'diagnose', ...$argv]);

        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $this->assertMatchesRegularExpression('/\n\s+blade\s+' . $expected . '\s+\(cli\)\n/', $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function bladeFlagOrders(): iterable
    {
        yield 'flag then option' => [['--blade', '--plugin-option', 'blade=false'], 'false'];
        yield 'option then flag' => [['--plugin-option=blade=false', '--blade'], 'true'];
        yield 'option then negated flag' => [['--plugin-option', 'blade=true', '--no-blade'], 'false'];
    }

    #[Test]
    public function an_invalid_override_is_reported_as_a_failure_without_a_stack_trace(): void
    {
        $tester = $this->realTester(['psalm-laravel', 'diagnose', '--plugin-option', 'blade=maybe']);

        $exit = $tester->execute([]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('Hard failures', $display);
        $this->assertStringContainsString("invalid value 'maybe' for key 'blade'", $display);
        $this->assertStringNotContainsString('Stack trace', $display);
    }

    #[Test]
    public function a_malformed_psalm_xml_is_a_hard_failure(): void
    {
        $failures = $this->collectIn('<psalm><plugins>')->hardFailures;

        $this->assertContains('Plugin settings: psalm.xml is not well-formed XML.', $failures);
    }

    #[Test]
    public function without_a_psalm_xml_every_setting_is_a_default(): void
    {
        $report = $this->collectIn(null);

        $this->assertNotSame([], $report->pluginSettings);
        $this->assertSame(['default'], \array_values(\array_unique(\array_column($report->pluginSettings, 'source'))));
        $this->assertSame('runtime', $report->phpAnalysisSource);
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


    /** Collects from a temp project root holding the given psalm.xml, or none. */
    private function collectIn(?string $psalmXml): Report
    {
        $root = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'psalm-laravel-diagnose-' . \uniqid('', true);
        \mkdir($root);
        $previous = \getcwd();
        \assert(\is_string($previous));

        if ($psalmXml !== null) {
            \file_put_contents($root . '/psalm.xml', $psalmXml);
        }

        try {
            \chdir($root);

            return (new Diagnostics())->collect();
        } finally {
            \chdir($previous);
            @\unlink($root . '/psalm.xml');
            @\rmdir($root);
        }
    }

    /**
     * @param list<string> $argv
     */
    private function realTester(array $argv): CommandTester
    {
        $app = new Application();
        $app->addCommand(new DiagnoseCommand(argvOverride: $argv));

        return new CommandTester($app->find('diagnose'));
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
            public function collect(array $cliOptions = []): Report
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
            bootMode: 'bootstrap',
            bootPath: '/app/bootstrap/app.php',
            bootstrapErrors: [],
            hardFailures: [],
            loadedProviders: [
                'Illuminate\\Auth\\AuthServiceProvider',
                'Illuminate\\Database\\DatabaseServiceProvider',
            ],
            pluginSettings: [
                ['key' => 'blade', 'value' => 'false', 'source' => 'cli'],
                ['key' => 'findUnregisteredRouteNames', 'value' => 'true', 'source' => 'derived (experimental)'],
            ],
        );
    }
}
