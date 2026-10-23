<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Cli\AnalyzeCommand;
use Psalm\LaravelPlugin\Config\PluginOverrides;
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

    private const ENV_VARS = ['PSALM_LARAVEL_INTERNAL_OPTIONS', 'PSALM_LARAVEL_TEST_MARKER'];

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
    public function strips_plugin_options_in_order_and_keeps_the_value_verbatim(): void
    {
        $this->assertSame(
            [
                'forwarded' => ['--threads=1', 'src'],
                'options' => ['blade=true', 'modelProperties.columnFallback=none', 'a=b=c', 'blade=false'],
            ],
            (new AnalyzeCommand())->scanArguments([
                'psalm-laravel', 'analyze', '--threads=1',
                '--plugin-option=blade=true',
                '--plugin-option=modelProperties.columnFallback=none',
                'src',
                '--plugin-option=a=b=c',
                '--plugin-option=blade=false',
            ]),
        );
    }

    #[Test]
    public function blade_shorthands_share_the_ordered_list_with_plugin_options_so_the_last_one_wins(): void
    {
        $this->assertSame(
            ['forwarded' => ['src', '--', '--blade'], 'options' => ['blade=true', 'blade=false', 'blade=false', 'blade=true']],
            (new AnalyzeCommand())->scanArguments([
                'psalm-laravel', '--blade', '--plugin-option=blade=false', 'src', '--no-blade', '--plugin-option=blade=true', '--', '--blade',
            ]),
        );
    }

    #[Test]
    public function the_experimental_shorthand_is_ordered_with_plugin_options(): void
    {
        $this->assertSame(
            ['forwarded' => ['src'], 'options' => ['experimental=false', 'experimental=true']],
            (new AnalyzeCommand())->scanArguments(['psalm-laravel', 'analyze', '--plugin-option=experimental=false', '--experimental', 'src']),
        );
    }

    #[Test]
    public function tokens_after_the_double_dash_boundary_are_forwarded_untouched(): void
    {
        $this->assertSame(
            ['forwarded' => ['--', '--plugin-option=x=1', '--plugin-option', 'src'], 'options' => ['a=b']],
            (new AnalyzeCommand())->scanArguments(['psalm-laravel', 'analyze', '--plugin-option=a=b', '--', '--plugin-option=x=1', '--plugin-option', 'src']),
        );
    }

    #[Test]
    public function flags_that_merely_resemble_the_override_flag_are_forwarded(): void
    {
        $this->assertSame(
            ['forwarded' => ['--blade=true', '--blades', '--no-blade-x', '--experimental=true', '--no-experimental', '--plugin', '--plugin-options=x', '--plugin-optionx=1'], 'options' => []],
            (new AnalyzeCommand())->scanArguments(['psalm-laravel', 'analyze', '--blade=true', '--blades', '--no-blade-x', '--experimental=true', '--no-experimental', '--plugin', '--plugin-options=x', '--plugin-optionx=1']),
        );
    }

    /** @return iterable<string, array{list<string>}> */
    public static function malformedPluginOptions(): iterable
    {
        yield 'bare, at the end' => [['--plugin-option']];
        yield 'spaced form' => [['--plugin-option', 'blade=true']];
        yield 'empty' => [['--plugin-option=']];
        yield 'whitespace in the value' => [['--plugin-option=blade=true experimental=true']];
        yield 'trailing newline' => [["--plugin-option=blade=true\n"]];
    }

    /** @param list<string> $args */
    #[Test]
    #[DataProvider('malformedPluginOptions')]
    public function a_malformed_plugin_option_is_an_error_not_forwarded(array $args): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/use --plugin-option=KEY=VALUE/');

        (new AnalyzeCommand())->scanArguments(['psalm-laravel', 'analyze', ...$args]);
    }

    #[Test]
    public function the_option_is_declared_and_the_help_lists_every_key_with_its_values(): void
    {
        $command = new AnalyzeCommand();

        $this->assertTrue($command->getDefinition()->getOption('plugin-option')->isArray());
        $this->assertTrue($command->getDefinition()->hasNegation('no-blade'));
        $this->assertTrue($command->getDefinition()->hasOption('experimental'));

        $help = $command->getHelp();
        foreach (\array_keys(PluginOverrides::KEYS) as $key) {
            $this->assertMatchesRegularExpression('/^  ' . \preg_quote($key, '/') . ' +\S/m', $help);
        }

        $this->assertMatchesRegularExpression('/modelProperties\.columnFallback +migrations\|none$/m', $help);
        $this->assertMatchesRegularExpression('/^  blade +true\|false$/m', $help);
    }

    #[Test]
    public function an_inherited_options_variable_is_replaced_never_merged(): void
    {
        \putenv('PSALM_LARAVEL_INTERNAL_OPTIONS=experimental=true blade=true');

        $report = $this->assertRan($this->runAgainstFakePsalm(['psalm-laravel', 'analyze', '--plugin-option=experimental=false', '--threads=1']));

        $this->assertSame('experimental=false', $report['options']);
        $this->assertSame(['--threads=1'], $report['argv'], 'an inherited blade token must not reach the child or the cache gate');

        $report = $this->assertRan($this->runAgainstFakePsalm(['psalm-laravel', 'analyze', '--threads=1']));

        $this->assertFalse($report['options'], 'without --plugin-option the inherited value is dropped');
    }

    #[Test]
    public function the_child_keeps_the_callers_whole_environment_when_options_are_given(): void
    {
        // proc_open REPLACES the environment when handed an array, so a child that lost PATH or any
        // other inherited variable would mean the handoff built its array from scratch.
        \putenv('PSALM_LARAVEL_TEST_MARKER=kept');

        $report = $this->assertRan($this->runAgainstFakePsalm(['psalm-laravel', 'analyze', '--plugin-option=experimental=true']));

        $this->assertTrue($report['path']);
        $this->assertSame('kept', $report['marker']);
    }

    #[Test]
    public function without_options_the_child_environment_and_arguments_are_untouched(): void
    {
        \putenv('PSALM_LARAVEL_TEST_MARKER=kept');

        $report = $this->assertRan($this->runAgainstFakePsalm(['psalm-laravel', 'analyze', '--threads=1']));

        $this->assertFalse($report['options']);
        $this->assertTrue($report['path']);
        $this->assertSame('kept', $report['marker']);
        $this->assertSame(['--threads=1'], $report['argv']);
    }

    /** @return iterable<string, array{list<string>, list<string>}> */
    public static function referenceCacheGate(): iterable
    {
        $flag = '--no-reference-cache';

        yield 'cli blade' => [['--plugin-option=blade=true', 'src'], [$flag, 'src']];
        yield '--no-blade shorthand' => [['--no-blade', 'src'], [$flag, 'src']];
        yield 'once for several blade overrides' => [['--blade', '--plugin-option=blade=false'], [$flag]];
        yield 'blade sub-settings only' => [['--plugin-option=blade.validateViewData=true'], []];
        yield 'other keys only' => [['--plugin-option=experimental=true'], []];
        yield 'no overrides' => [['--threads=1'], ['--threads=1']];
        // psalm tolerates the duplicates; the wrapper cannot know which flags psalm's getopt will parse.
        yield 'user passed --no-cache' => [['--plugin-option=blade=true', '--no-cache'], [$flag, '--no-cache']];
        yield 'user passed --no-reference-cache' => [['--no-reference-cache', '--plugin-option=blade=true'], [$flag, $flag]];
        yield 'getopt stops at the first positional, so a later --no-cache is not parsed' => [
            ['--plugin-option=blade=false', '--find-dead-code=always', 'src', '--no-cache'], [$flag, '--find-dead-code=always', 'src', '--no-cache'],
        ];
        yield 'a boundary hides psalm flags after it' => [['--plugin-option=blade=true', '--', '--no-cache'], [$flag, '--', '--no-cache']];
    }

    /**
     * @param list<string> $args
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('referenceCacheGate')]
    public function blade_overrides_prepend_no_reference_cache_before_every_forwarded_token(array $args, array $expected): void
    {
        $report = $this->assertRan($this->runAgainstFakePsalm(['psalm-laravel', 'analyze', ...$args]));

        $this->assertSame($expected, $report['argv']);
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function invalidInput(): iterable
    {
        yield 'unknown key' => [['--plugin-option=nope=1'], "--plugin-option: unknown key 'nope'"];
        yield 'bad bool' => [['--plugin-option=blade=yes'], "invalid value 'yes'"];
        yield 'missing equals' => [['--plugin-option=blade'], 'is not KEY=VALUE'];
        yield 'bare option' => [['--plugin-option'], 'use --plugin-option=KEY=VALUE'];
        yield 'whitespace in the value' => [['--plugin-option=blade=true experimental=true'], 'use --plugin-option=KEY=VALUE'];
    }

    /** @param list<string> $args */
    #[Test]
    #[DataProvider('invalidInput')]
    public function invalid_options_fail_before_psalm_is_launched(array $args, string $message): void
    {
        $run = $this->runAgainstFakePsalm(['psalm-laravel', 'analyze', ...$args]);

        $this->assertSame(Command::FAILURE, $run['exit']);
        $this->assertNull($run['report'], 'psalm must not be launched with invalid overrides');
        $this->assertStringContainsString($message, \preg_replace('/\s+/', ' ', $run['display']) ?? '');
    }

    /**
     * @param array{exit: int, display: string, report: ?array{options: string|false, path: bool, marker: string|false, argv: list<string>}} $run
     *
     * @return array{options: string|false, path: bool, marker: string|false, argv: list<string>}
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
     * @return array{exit: int, display: string, report: ?array{options: string|false, path: bool, marker: string|false, argv: list<string>}}
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
                'options' => getenv('PSALM_LARAVEL_INTERNAL_OPTIONS'),
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
        /** @var array{options: string|false, path: bool, marker: string|false, argv: list<string>}|null $report */
        $report = \is_string($json) ? \json_decode($json, true, 512, \JSON_THROW_ON_ERROR) : null;

        return ['exit' => $exit, 'display' => $tester->getDisplay(), 'report' => $report];
    }
}
