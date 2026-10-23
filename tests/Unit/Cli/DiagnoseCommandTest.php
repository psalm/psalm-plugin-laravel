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


    #[Test]
    public function renders_the_blade_status_line(): void
    {
        $base = $this->okReport();
        $report = new Report(
            pluginVersion: $base->pluginVersion,
            psalmVersion: $base->psalmVersion,
            laravelVersion: $base->laravelVersion,
            phpRuntimeVersion: $base->phpRuntimeVersion,
            phpAnalysisVersion: $base->phpAnalysisVersion,
            phpAnalysisSource: $base->phpAnalysisSource,
            bootMode: $base->bootMode,
            bootPath: $base->bootPath,
            bootstrapErrors: [],
            hardFailures: [],
            loadedProviders: [],
            blade: 'disabled: the view finder could not be resolved',
        );

        $tester = $this->testerFor($this->fixtureProvider($report));
        $tester->execute([]);

        $this->assertMatchesRegularExpression('/^Blade: disabled: the view finder could not be resolved$/m', $tester->getDisplay());
    }

    #[Test]
    public function blade_is_off_without_a_psalm_config_or_without_the_blade_element(): void
    {
        $root = $this->bladeProject(null);

        $this->assertSame('off', (new Diagnostics())->bladeStatus($root));

        $this->writePsalmXml($root, '<pluginClass class="Psalm\LaravelPlugin\Plugin" />');
        $this->assertSame('off', (new Diagnostics())->bladeStatus($root));

        $this->writePsalmXml($root, '<pluginClass class="Psalm\LaravelPlugin\Plugin"><blade value="false" /></pluginClass>');
        $this->assertSame('off', (new Diagnostics())->bladeStatus($root));
    }

    #[Test]
    public function blade_is_on_with_its_template_count_and_cache_directory(): void
    {
        $root = $this->bladeProject('{root}/blade-cache');

        $this->assertMatchesRegularExpression(
            '/^on \(\d+ templates?, cache ' . \preg_quote($root . '/blade-cache', '/') . '\)$/',
            (new Diagnostics())->bladeStatus($root),
        );
    }

    #[Test]
    public function blade_reports_the_reason_it_disabled_itself(): void
    {
        // A cache directory nested under a regular file can never be created.
        $root = $this->bladeProject('{root}/not-a-dir/blade');
        \file_put_contents($root . '/not-a-dir', '');

        $this->assertSame(
            "disabled: the shadow cache directory '{$root}/not-a-dir/blade' could not be created",
            (new Diagnostics())->bladeStatus($root),
        );
    }

    #[Test]
    public function blade_reads_a_dist_config_when_there_is_no_psalm_xml(): void
    {
        foreach (['psalm.xml.dist', 'psalm.dist.xml'] as $fileName) {
            $root = $this->bladeProject(null);
            $this->writePsalmXml($root, '<pluginClass class="Psalm\\LaravelPlugin\\Plugin"><blade /></pluginClass>', $fileName);

            $this->assertSame("disabled: loaded {$root}/{$fileName}", $this->psalmConfigLoadRecorder()->bladeStatus($root));
        }
    }

    #[Test]
    public function an_empty_blade_cache_dir_falls_back_to_the_psalm_cache_like_a_real_run(): void
    {
        $root = $this->bladeProject('');

        $this->assertSame("disabled: loaded {$root}/psalm.xml", $this->psalmConfigLoadRecorder()->bladeStatus($root));
    }

    #[Test]
    public function blade_reads_plugin_settings_pulled_in_through_xinclude(): void
    {
        $root = $this->bladeProject(null);
        \file_put_contents($root . '/not-a-dir', '');
        \file_put_contents(
            $root . '/plugins.xml',
            "<pluginClass xmlns=\"https://getpsalm.org/schema/config\" class=\"Psalm\\LaravelPlugin\\Plugin\"><blade cacheDir=\"{$root}/not-a-dir/blade\" /></pluginClass>",
        );
        $this->writePsalmXml($root, '<xi:include xmlns:xi="http://www.w3.org/2001/XInclude" href="plugins.xml" />');

        $this->assertSame(
            "disabled: the shadow cache directory '{$root}/not-a-dir/blade' could not be created",
            (new Diagnostics())->bladeStatus($root),
        );
    }

    /** Stops at the Psalm config load, naming the file it would load: loading plants Psalm's Config singleton. */
    private function psalmConfigLoadRecorder(): Diagnostics
    {
        return new class extends Diagnostics {
            #[\Override]
            protected function loadPsalmConfig(string $psalmXml, string $projectRoot): void
            {
                throw new \RuntimeException('loaded ' . $psalmXml);
            }
        };
    }

    /** @var list<string> */
    private array $tempDirs = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($dir);
        }
    }

    /** A temp project root; with a cache directory given, its psalm.xml enables Blade there. */
    private function bladeProject(?string $cacheDir): string
    {
        $root = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'psalm-laravel-diagnose-' . \uniqid('', true);
        \mkdir($root);
        $this->tempDirs[] = $root;

        if ($cacheDir !== null) {
            $cacheDir = \str_replace('{root}', $root, $cacheDir);
            $this->writePsalmXml($root, "<pluginClass class=\"Psalm\\LaravelPlugin\\Plugin\"><blade cacheDir=\"{$cacheDir}\" /></pluginClass>");
        }

        return $root;
    }

    private function writePsalmXml(string $root, string $plugins, string $fileName = 'psalm.xml'): void
    {
        \file_put_contents(
            $root . '/' . $fileName,
            '<?xml version="1.0"?><psalm xmlns="https://getpsalm.org/schema/config"><plugins>' . $plugins . '</plugins></psalm>',
        );
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
