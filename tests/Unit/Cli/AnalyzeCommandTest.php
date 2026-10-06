<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Cli\AnalyzeCommand;
use Psalm\LaravelPlugin\Config\Setting;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(AnalyzeCommand::class)]
final class AnalyzeCommandTest extends TestCase
{
    private string $tempDir;

    private ?string $originalArgv = null;

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    private const ENV_VARS = ['PSALM_LARAVEL_OPTIONS', 'PSALM_LARAVEL_CLI_OPTIONS', 'PSALM_LARAVEL_TEST_MARKER'];

    protected function setUp(): void
    {
        $this->tempDir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'psalm-laravel-analyze-' . \uniqid('', true);
        if (! \mkdir($this->tempDir) && ! \is_dir($this->tempDir)) {
            throw new \RuntimeException(\sprintf('Failed to create temp directory %s', $this->tempDir));
        }

        // The process argv and the options variable are read by the command itself, so every test starts
        // from a known state and tearDown() puts the real values back for the rest of the suite.
        $this->originalArgv = isset($_SERVER['argv']) ? \serialize($_SERVER['argv']) : null;
        foreach (self::ENV_VARS as $name) {
            $this->originalEnv[$name] = \getenv($name);
            \putenv($name);
        }
    }

    protected function tearDown(): void
    {
        if ($this->originalArgv === null) {
            unset($_SERVER['argv']);
        } else {
            $_SERVER['argv'] = \unserialize($this->originalArgv, ['allowed_classes' => false]);
        }

        foreach ($this->originalEnv as $name => $value) {
            \putenv($value === false ? $name : $name . '=' . $value);
        }

        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $directory): void
    {
        if (! \is_dir($directory)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var \SplFileInfo $entry */
        foreach ($entries as $entry) {
            $entry->isDir() && ! $entry->isLink() ? @\rmdir($entry->getPathname()) : @\unlink($entry->getPathname());
        }

        @\rmdir($directory);
    }

    #[Test]
    public function fails_cleanly_when_psalm_binary_is_missing(): void
    {
        $command = new AnalyzeCommand($this->tempDir);
        $application = new Application();
        $application->addCommand($command);

        $tester = new CommandTester($application->find('analyze'));

        $exit = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exit);
        $display = \str_replace(\DIRECTORY_SEPARATOR, '/', $tester->getDisplay());
        $this->assertStringContainsString('Could not find', $display);
        $this->assertStringContainsString('vendor/bin/psalm', $display);

        // Enriched launch diagnostics (#1195): check the underlying dynamic
        // values are surfaced (proves the diagnostics reflect the real
        // environment, not placeholder text), not the exact label wording
        // around them, which may reasonably change.
        $this->assertStringContainsString(\PHP_BINARY, $display);
        $this->assertStringContainsString(\str_replace(\DIRECTORY_SEPARATOR, '/', $this->tempDir), $display);
    }

    #[Test]
    public function analyse_is_registered_as_an_alias(): void
    {
        $application = new Application();
        $application->addCommand(new AnalyzeCommand($this->tempDir));

        // Application::find() accepts both the canonical name and any alias.
        $this->assertSame($application->find('analyze'), $application->find('analyse'));
    }

    #[Test]
    public function forwards_flags_after_the_explicit_command_name(): void
    {
        $command = new AnalyzeCommand();

        $this->assertSame(
            ['--set-baseline=psalm-baseline.xml'],
            $command->scanArguments(['psalm-laravel', 'analyze', '--set-baseline=psalm-baseline.xml'])['forwarded'],
        );
    }

    #[Test]
    public function forwards_flags_after_the_command_alias(): void
    {
        $command = new AnalyzeCommand();

        $this->assertSame(
            ['--threads=1'],
            $command->scanArguments(['psalm-laravel', 'analyse', '--threads=1'])['forwarded'],
        );
    }

    #[Test]
    public function forwards_flags_for_the_default_command_form_without_a_name_token(): void
    {
        // `psalm-laravel --set-baseline=...` routes here via the default command,
        // so there is no command-name token to strip.
        $command = new AnalyzeCommand();

        $this->assertSame(
            ['--set-baseline=psalm-baseline.xml'],
            $command->scanArguments(['psalm-laravel', '--set-baseline=psalm-baseline.xml'])['forwarded'],
        );
    }

    #[Test]
    public function forwards_multiple_flags_and_path_arguments_verbatim(): void
    {
        $command = new AnalyzeCommand();

        $this->assertSame(
            ['--set-baseline=foo.xml', '--no-cache', 'src'],
            $command->scanArguments(['psalm-laravel', 'analyze', '--set-baseline=foo.xml', '--no-cache', 'src'])['forwarded'],
        );
    }

    #[Test]
    public function forwards_nothing_when_only_the_command_name_is_present(): void
    {
        $command = new AnalyzeCommand();

        $this->assertSame([], $command->scanArguments(['psalm-laravel', 'analyze'])['forwarded']);
    }

    #[Test]
    public function reads_the_process_argv_when_no_override_is_given(): void
    {
        // This is the path execute() actually hits — scanArguments() with no
        // argument falls back to $_SERVER['argv']. tearDown() restores it so the
        // rest of the suite is unaffected.
        $_SERVER['argv'] = ['psalm-laravel', 'analyze', '--set-baseline=psalm-baseline.xml'];

        $this->assertSame(['--set-baseline=psalm-baseline.xml'], (new AnalyzeCommand())->scanArguments()['forwarded']);
    }

    #[Test]
    public function forwards_nothing_when_the_process_argv_is_unavailable(): void
    {
        // `register_argc_argv = Off` leaves $_SERVER['argv'] unset; the `?? []`
        // fallback must yield an empty list rather than erroring.
        unset($_SERVER['argv']);

        $this->assertSame(
            ['forwarded' => [], 'options' => []],
            (new AnalyzeCommand())->scanArguments(),
        );
    }

    #[Test]
    public function strips_blade_flags_from_the_forwarded_tokens_and_turns_them_into_blade_options(): void
    {
        $command = new AnalyzeCommand();

        $this->assertSame(
            ['forwarded' => ['--threads=1', 'src', '--no-cache'], 'options' => ['blade=true']],
            $command->scanArguments(['psalm-laravel', 'analyze', '--threads=1', '--blade', 'src', '--no-cache']),
        );
        $this->assertSame(
            ['forwarded' => ['--no-cache'], 'options' => ['blade=false']],
            $command->scanArguments(['psalm-laravel', '--no-blade', '--no-cache']),
        );
        $this->assertSame([], $command->scanArguments(['psalm-laravel', 'analyze', '--threads=1'])['options']);
    }

    #[Test]
    public function strips_both_spellings_of_plugin_option_and_keeps_the_value_verbatim(): void
    {
        $command = new AnalyzeCommand();

        $this->assertSame(
            [
                'forwarded' => ['--threads=1', 'src'],
                'options' => ['experimental=true', 'modelProperties.columnFallback=none', 'blade.cacheDir=/tmp/blade shadows', 'a=b=c'],
            ],
            $command->scanArguments([
                'psalm-laravel', 'analyze', '--threads=1',
                '--plugin-option', 'experimental=true',
                '--plugin-option=modelProperties.columnFallback=none',
                '--plugin-option', 'blade.cacheDir=/tmp/blade shadows',
                'src',
                '--plugin-option=a=b=c',
            ]),
        );
    }

    #[Test]
    public function blade_flags_and_plugin_options_share_one_ordered_list_so_the_last_one_wins(): void
    {
        $this->assertSame(
            ['forwarded' => [], 'options' => ['blade=true', 'blade=false', 'blade=false', 'blade=true']],
            (new AnalyzeCommand())->scanArguments([
                'psalm-laravel', 'analyze', '--blade', '--plugin-option', 'blade=false', '--no-blade', '--plugin-option=blade=true',
            ]),
        );
    }

    #[Test]
    public function tokens_after_the_double_dash_boundary_are_forwarded_untouched(): void
    {
        $command = new AnalyzeCommand();

        $this->assertSame(
            ['forwarded' => ['--', '--blade', '--plugin-option', 'x=1', 'src'], 'options' => ['blade=false', 'a=b']],
            $command->scanArguments(['psalm-laravel', 'analyze', '--no-blade', '--plugin-option', 'a=b', '--', '--blade', '--plugin-option', 'x=1', 'src']),
        );
        $this->assertSame([], $command->scanArguments(['psalm-laravel', 'analyze', '--', '--blade'])['options']);
    }

    #[Test]
    public function flags_that_merely_resemble_the_override_flags_are_forwarded(): void
    {
        $command = new AnalyzeCommand();

        $this->assertSame(
            ['forwarded' => ['--blade=true', '--blades', '--no-blade-x', '--plugin', '--plugin-options=x', 'blade'], 'options' => []],
            $command->scanArguments(['psalm-laravel', 'analyze', '--blade=true', '--blades', '--no-blade-x', '--plugin', '--plugin-options=x', 'blade']),
        );
    }

    /** @return iterable<string, array{list<string>}> */
    public static function danglingPluginOptions(): iterable
    {
        yield 'at the end' => [['psalm-laravel', 'analyze', '--plugin-option']];
        yield 'followed by a flag' => [['psalm-laravel', 'analyze', '--plugin-option', '--blade']];
        yield 'followed by the boundary' => [['psalm-laravel', 'analyze', '--plugin-option', '--', 'src']];
    }

    /** @param list<string> $argv */
    #[Test]
    #[DataProvider('danglingPluginOptions')]
    public function a_plugin_option_without_a_value_is_an_error(array $argv): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/--plugin-option.*requires a KEY=VALUE/');

        (new AnalyzeCommand())->scanArguments($argv);
    }

    #[Test]
    public function the_override_options_are_declared_and_the_help_lists_every_key_with_type_and_default(): void
    {
        $command = new AnalyzeCommand();
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('blade'));
        $this->assertTrue($definition->hasNegation('no-blade'));
        $this->assertTrue($definition->hasOption('plugin-option'));
        $this->assertTrue($definition->getOption('plugin-option')->isArray());

        $help = $command->getHelp();
        foreach (Setting::all() as $setting) {
            $this->assertStringContainsString($setting->key, $help);
        }

        $this->assertMatchesRegularExpression('/modelProperties\.columnFallback\s+migrations\|none\s+\(default: migrations\)/', $help);
        $this->assertMatchesRegularExpression('/configDirectory\s+path, repeatable\s+\(default: none/', $help);
        $this->assertMatchesRegularExpression('/findUnregisteredRouteNames\s+true\|false\s+\(default: experimental\)/', $help);
    }

    #[Test]
    public function cli_options_reach_the_child_as_private_json_and_never_touch_the_user_variable(): void
    {
        $run = $this->runAgainstFakePsalm(['psalm-laravel', 'analyze', '--threads=1', '--plugin-option', 'blade.cacheDir=/tmp/blade shadows', '--blade']);

        $this->assertSame(Command::SUCCESS, $run['exit']);
        $report = $this->assertRan($run);
        $this->assertSame('["blade.cacheDir=\/tmp\/blade shadows","blade=true"]', $report['cli']);
        $this->assertFalse($report['options'], 'analyze must not write into PSALM_LARAVEL_OPTIONS');
        $this->assertSame(['--no-reference-cache', '--threads=1'], $report['argv']);
    }

    #[Test]
    public function an_inherited_options_variable_is_passed_through_verbatim(): void
    {
        \putenv('PSALM_LARAVEL_OPTIONS=experimental=true blade=true');

        $report = $this->assertRan($this->runAgainstFakePsalm(['psalm-laravel', 'analyze', '--no-blade']));

        $this->assertSame('experimental=true blade=true', $report['options']);
        $this->assertSame('["blade=false"]', $report['cli']);
    }

    #[Test]
    public function the_child_keeps_the_callers_whole_environment_when_options_are_given(): void
    {
        // proc_open REPLACES the environment when handed an array, so a child that lost PATH or any
        // other inherited variable would mean the handoff built its array from scratch.
        \putenv('PSALM_LARAVEL_TEST_MARKER=kept');

        $report = $this->assertRan($this->runAgainstFakePsalm(['psalm-laravel', 'analyze', '--plugin-option', 'experimental=true']));

        $this->assertTrue($report['path']);
        $this->assertSame('kept', $report['marker']);
    }

    #[Test]
    public function without_options_the_child_environment_and_arguments_are_untouched(): void
    {
        \putenv('PSALM_LARAVEL_TEST_MARKER=kept');

        $report = $this->assertRan($this->runAgainstFakePsalm(['psalm-laravel', 'analyze', '--threads=1']));

        $this->assertFalse($report['options'], 'an unset PSALM_LARAVEL_OPTIONS must stay unset');
        $this->assertFalse($report['cli']);
        $this->assertTrue($report['path']);
        $this->assertSame('kept', $report['marker']);
        $this->assertSame(['--threads=1'], $report['argv']);

        \putenv('PSALM_LARAVEL_OPTIONS=experimental=true');

        $report = $this->assertRan($this->runAgainstFakePsalm(['psalm-laravel', 'analyze']));

        $this->assertSame('experimental=true', $report['options']);
        $this->assertSame([], $report['argv']);
    }

    /** @return iterable<string, array{list<string>, ?string, list<string>}> */
    public static function referenceCacheGate(): iterable
    {
        $flag = '--no-reference-cache';

        yield '--blade' => [['--blade'], null, [$flag]];
        yield '--no-blade' => [['--no-blade'], null, [$flag]];
        yield '--plugin-option blade=…' => [['--plugin-option', 'blade=true', 'src'], null, [$flag, 'src']];
        yield 'inherited env blade' => [['--threads=1'], 'blade=false', [$flag, '--threads=1']];
        yield 'once for several blade overrides' => [['--blade', '--plugin-option', 'blade=false'], 'blade=true', [$flag]];
        yield 'blade sub-settings only' => [['--plugin-option', 'blade.cacheDir=/tmp/x'], 'blade.validateViewData=true', []];
        yield 'other keys only' => [['--plugin-option', 'experimental=true'], 'configDirectory=a', []];
        yield 'no overrides' => [['--threads=1'], null, ['--threads=1']];
        yield 'user passed --no-cache' => [['--blade', '--no-cache'], null, ['--no-cache']];
        yield 'user passed --no-reference-cache' => [['--no-reference-cache', '--blade'], null, [$flag]];
        yield 'a boundary hides psalm flags after it' => [['--blade', '--', '--no-cache'], null, [$flag, '--', '--no-cache']];
    }

    /**
     * @param list<string> $args
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('referenceCacheGate')]
    public function blade_overrides_add_no_reference_cache_unless_the_cache_is_already_off(array $args, ?string $env, array $expected): void
    {
        if ($env !== null) {
            \putenv('PSALM_LARAVEL_OPTIONS=' . $env);
        }

        $report = $this->assertRan($this->runAgainstFakePsalm(['psalm-laravel', 'analyze', ...$args]));

        $this->assertSame($expected, $report['argv']);
    }

    /** @return iterable<string, array{list<string>, ?string, string}> */
    public static function invalidInput(): iterable
    {
        yield 'unknown key' => [['--plugin-option', 'nope=1'], null, "unknown key 'nope'"];
        yield 'bad bool' => [['--plugin-option', 'blade=yes'], null, "invalid value 'yes'"];
        yield 'bad enum' => [['--plugin-option', 'modelProperties.columnFallback=db'], null, 'Valid values'];
        yield 'missing equals' => [['--plugin-option', 'blade'], null, 'expected KEY=VALUE'];
        yield 'empty value' => [['--plugin-option=blade='], null, 'expected KEY=VALUE'];
        yield 'empty key' => [['--plugin-option', '=true'], null, 'expected KEY=VALUE'];
        yield 'empty token' => [['--plugin-option='], null, 'expected KEY=VALUE'];
        yield 'dangling option' => [['--plugin-option'], null, 'requires a KEY=VALUE'];
        yield 'bad env key' => [[], 'bladee=true', "PSALM_LARAVEL_OPTIONS: unknown key 'bladee'"];
        yield 'quoted env value' => [[], 'blade.cacheDir="/tmp/a b"', 'PSALM_LARAVEL_OPTIONS'];
        yield 'valid cli over bad env' => [['--blade'], 'blade=maybe', "PSALM_LARAVEL_OPTIONS: invalid value 'maybe'"];
    }

    /** @param list<string> $args */
    #[Test]
    #[DataProvider('invalidInput')]
    public function invalid_options_fail_before_psalm_is_launched(array $args, ?string $env, string $message): void
    {
        if ($env !== null) {
            \putenv('PSALM_LARAVEL_OPTIONS=' . $env);
        }

        $run = $this->runAgainstFakePsalm(['psalm-laravel', 'analyze', ...$args]);

        $this->assertSame(Command::FAILURE, $run['exit']);
        $this->assertNull($run['report'], 'psalm must not be launched with invalid overrides');
        $this->assertStringContainsString($message, \preg_replace('/\s+/', ' ', $run['display']) ?? '');
    }

    /**
     * @param array{exit: int, display: string, report: ?array{options: string|false, cli: string|false, path: bool, marker: string|false, argv: list<string>}} $run
     *
     * @return array{options: string|false, cli: string|false, path: bool, marker: string|false, argv: list<string>}
     */
    private function assertRan(array $run): array
    {
        $this->assertNotNull($run['report'], 'the stand-in psalm never ran: ' . $run['display']);

        return $run['report'];
    }

    /**
     * Runs the command against a stand-in `vendor/bin/psalm` that records what it was launched with.
     *
     * @param list<string> $argv
     *
     * @return array{exit: int, display: string, report: ?array{options: string|false, cli: string|false, path: bool, marker: string|false, argv: list<string>}}
     */
    private function runAgainstFakePsalm(array $argv): array
    {
        $binDir = $this->tempDir . '/vendor/bin';
        if (! \mkdir($binDir, 0777, true) && ! \is_dir($binDir)) {
            throw new \RuntimeException(\sprintf('Failed to create %s', $binDir));
        }

        \file_put_contents($binDir . '/psalm', <<<'PHP'
            <?php
            file_put_contents(__DIR__ . '/report.json', json_encode([
                'options' => getenv('PSALM_LARAVEL_OPTIONS'),
                'cli' => getenv('PSALM_LARAVEL_CLI_OPTIONS'),
                'path' => getenv('PATH') !== false,
                'marker' => getenv('PSALM_LARAVEL_TEST_MARKER'),
                'argv' => array_slice($argv, 1),
            ]));
            PHP);
        @\unlink($binDir . '/report.json');

        $_SERVER['argv'] = $argv;

        $tester = new CommandTester(new AnalyzeCommand($this->tempDir));
        $exit = $tester->execute([]);

        $json = @\file_get_contents($binDir . '/report.json');
        /** @var array{options: string|false, cli: string|false, path: bool, marker: string|false, argv: list<string>}|null $report */
        $report = \is_string($json) ? \json_decode($json, true, 512, \JSON_THROW_ON_ERROR) : null;

        return ['exit' => $exit, 'display' => $tester->getDisplay(), 'report' => $report];
    }
}
