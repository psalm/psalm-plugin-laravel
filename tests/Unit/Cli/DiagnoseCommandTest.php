<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Bootstrap\ApplicationProvider;
use Psalm\LaravelPlugin\Cli\Diagnose\Diagnostics;
use Psalm\LaravelPlugin\Cli\Diagnose\Report;
use Psalm\LaravelPlugin\Cli\Diagnose\TipsProvider;
use Psalm\LaravelPlugin\Cli\DiagnoseCommand;
use Psalm\LaravelPlugin\Config\ExperimentalFeature;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DiagnoseCommand::class)]
#[CoversClass(Diagnostics::class)]
#[CoversClass(Report::class)]
#[CoversClass(TipsProvider::class)]
final class DiagnoseCommandTest extends TestCase
{
    private string $originalCwd;

    /** @var array<string, mixed> */
    private array $originalApplicationProviderState;

    private ?string $scratchDir = null;

    protected function setUp(): void
    {
        parent::setUp();
        $cwd = \getcwd();
        $this->assertIsString($cwd, 'Could not resolve the current working directory.');
        $this->originalCwd = $cwd;
        // Only collect_reads_enabled_experimental_features_from_the_projects_psalm_xml()
        // chdir()s and boots for real, but snapshotting/resetting here for every test keeps
        // this file order-independent regardless of which test runs first — mirrors
        // ApplicationProviderConfigPathTest's "a previous test that booted from a different
        // cwd cannot leak its state into this one" defense.
        $this->originalApplicationProviderState = [
            'app' => $this->reflectApplicationProviderProperty('app')->getValue(),
            'booted' => $this->reflectApplicationProviderProperty('booted')->getValue(),
            'bootMode' => $this->reflectApplicationProviderProperty('bootMode')->getValue(),
            'bootPath' => $this->reflectApplicationProviderProperty('bootPath')->getValue(),
            'bootstrapError' => $this->reflectApplicationProviderProperty('bootstrapError')->getValue(),
        ];
        $this->reflectApplicationProviderProperty('app')->setValue(null, null);
        $this->reflectApplicationProviderProperty('booted')->setValue(null, false);
        $this->reflectApplicationProviderProperty('bootMode')->setValue(null, null);
        $this->reflectApplicationProviderProperty('bootPath')->setValue(null, null);
        $this->reflectApplicationProviderProperty('bootstrapError')->setValue(null, null);
    }

    protected function tearDown(): void
    {
        \chdir($this->originalCwd);
        foreach ($this->originalApplicationProviderState as $property => $value) {
            $this->reflectApplicationProviderProperty($property)->setValue(null, $value);
        }

        if ($this->scratchDir !== null) {
            $this->removeRecursively($this->scratchDir);
            $this->scratchDir = null;
        }

        parent::tearDown();
    }

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
            experimentalFeaturesEnabled: $base->experimentalFeaturesEnabled,
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
            experimentalFeaturesEnabled: $base->experimentalFeaturesEnabled,
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
    public function experimental_section_shows_none_available_when_nothing_enabled(): void
    {
        $tester = $this->testerFor($this->fixtureProvider($this->okReport()));

        $exit = $tester->execute([]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $exit, $display);
        $this->assertStringContainsString('Experimental', $display);
        $available = \count(ExperimentalFeature::cases());
        $this->assertStringContainsString("none ({$available} available, see docs/config.md)", $display);
    }

    #[Test]
    public function experimental_section_lists_enabled_features(): void
    {
        $base = $this->okReport();
        $withExperiment = new Report(
            pluginVersion: $base->pluginVersion,
            psalmVersion: $base->psalmVersion,
            laravelVersion: $base->laravelVersion,
            phpRuntimeVersion: $base->phpRuntimeVersion,
            phpAnalysisVersion: $base->phpAnalysisVersion,
            phpAnalysisSource: $base->phpAnalysisSource,
            bootMode: $base->bootMode,
            bootPath: $base->bootPath,
            bootstrapErrors: $base->bootstrapErrors,
            hardFailures: $base->hardFailures,
            loadedProviders: $base->loadedProviders,
            experimentalFeaturesEnabled: ['modelToArrayShape'],
        );

        $tester = $this->testerFor($this->fixtureProvider($withExperiment));

        $exit = $tester->execute([]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $exit, $display);
        $this->assertStringContainsString('Experimental', $display);
        $this->assertStringContainsString('modelToArrayShape', $display);
        $this->assertStringNotContainsString('available, see docs/config.md', $display);
    }

    #[Test]
    public function collect_reads_enabled_experimental_features_from_the_projects_psalm_xml(): void
    {
        $this->scratchDir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . \uniqid('psalm-laravel-diagnose-', true);
        $this->assertTrue(\mkdir($this->scratchDir), "Could not create scratch dir {$this->scratchDir}");
        \file_put_contents(
            $this->scratchDir . \DIRECTORY_SEPARATOR . 'psalm.xml',
            '<?xml version="1.0"?>'
            . '<psalm xmlns="https://getpsalm.org/schema/config">'
            . '<plugins><pluginClass class="Psalm\\LaravelPlugin\\Plugin">'
            . '<experimental><feature name="modelToArrayShape" /></experimental>'
            . '</pluginClass></plugins>'
            . '</psalm>',
        );

        $this->assertTrue(\chdir($this->scratchDir));
        $report = (new Diagnostics())->collect();

        $this->assertSame(['modelToArrayShape'], $report->experimentalFeaturesEnabled);
    }

    #[Test]
    public function collect_reads_all_true_experimental_from_the_projects_psalm_xml(): void
    {
        // A functionally distinct code path from the named-<feature> test above
        // (readEnabledExperimentalFeatures()'s all="true" branch maps every
        // ExperimentalFeature::cases() instead of reading <feature> children) that
        // happens to look identical today because only one case exists.
        $this->scratchDir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . \uniqid('psalm-laravel-diagnose-', true);
        $this->assertTrue(\mkdir($this->scratchDir), "Could not create scratch dir {$this->scratchDir}");
        \file_put_contents(
            $this->scratchDir . \DIRECTORY_SEPARATOR . 'psalm.xml',
            '<?xml version="1.0"?>'
            . '<psalm xmlns="https://getpsalm.org/schema/config">'
            . '<plugins><pluginClass class="Psalm\\LaravelPlugin\\Plugin">'
            . '<experimental all="true" />'
            . '</pluginClass></plugins>'
            . '</psalm>',
        );

        $this->assertTrue(\chdir($this->scratchDir));
        $report = (new Diagnostics())->collect();

        $this->assertSame(['modelToArrayShape'], $report->experimentalFeaturesEnabled);
    }

    #[Test]
    public function collect_finds_this_plugins_pluginclass_among_several_siblings(): void
    {
        // findPluginClassElement() must skip a non-matching <pluginClass> (e.g. a sister
        // Psalm plugin registered in the same <plugins> block) and locate this plugin's own.
        $this->scratchDir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . \uniqid('psalm-laravel-diagnose-', true);
        $this->assertTrue(\mkdir($this->scratchDir), "Could not create scratch dir {$this->scratchDir}");
        \file_put_contents(
            $this->scratchDir . \DIRECTORY_SEPARATOR . 'psalm.xml',
            '<?xml version="1.0"?>'
            . '<psalm xmlns="https://getpsalm.org/schema/config">'
            . '<plugins>'
            . '<pluginClass class="Psalm\\PhpUnitPlugin\\Plugin" />'
            . '<pluginClass class="Psalm\\LaravelPlugin\\Plugin">'
            . '<experimental><feature name="modelToArrayShape" /></experimental>'
            . '</pluginClass>'
            . '</plugins>'
            . '</psalm>',
        );

        $this->assertTrue(\chdir($this->scratchDir));
        $report = (new Diagnostics())->collect();

        $this->assertSame(['modelToArrayShape'], $report->experimentalFeaturesEnabled);
    }

    private function reflectApplicationProviderProperty(string $name): \ReflectionProperty
    {
        return new \ReflectionProperty(ApplicationProvider::class, $name);
    }

    private function removeRecursively(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var \SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $entry->isDir() ? \rmdir($entry->getPathname()) : \unlink($entry->getPathname());
        }

        \rmdir($path);
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
            experimentalFeaturesEnabled: [],
        );
    }
}
