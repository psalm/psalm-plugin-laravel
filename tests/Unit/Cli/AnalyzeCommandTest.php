<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Cli\AnalyzeCommand;
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

    private const ENV_VARS = ['PSALM_LARAVEL_OPTIONS', 'PSALM_LARAVEL_TEST_MARKER'];

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
            ['forwarded' => [], 'blade' => null],
            (new AnalyzeCommand())->scanArguments(),
        );
    }

    #[Test]
    public function strips_blade_flags_from_the_forwarded_tokens_and_reports_the_override(): void
    {
        $command = new AnalyzeCommand();

        $this->assertSame(
            ['forwarded' => ['--threads=1', 'src', '--no-cache'], 'blade' => true],
            $command->scanArguments(['psalm-laravel', 'analyze', '--threads=1', '--blade', 'src', '--no-cache']),
        );
        $this->assertSame(
            ['forwarded' => ['--no-cache'], 'blade' => false],
            $command->scanArguments(['psalm-laravel', '--no-blade', '--no-cache']),
        );
    }

    #[Test]
    public function reports_no_blade_override_when_neither_flag_is_given(): void
    {
        $command = new AnalyzeCommand();

        $this->assertNull($command->scanArguments(['psalm-laravel', 'analyze', '--threads=1'])['blade']);
        $this->assertNull($command->scanArguments(['psalm-laravel', 'analyze'])['blade']);
    }

    #[Test]
    public function the_last_blade_flag_wins(): void
    {
        $command = new AnalyzeCommand();

        $this->assertSame(
            ['forwarded' => [], 'blade' => false],
            $command->scanArguments(['psalm-laravel', 'analyze', '--blade', '--no-blade', '--blade', '--no-blade']),
        );
        $this->assertTrue($command->scanArguments(['psalm-laravel', 'analyze', '--no-blade', '--blade'])['blade']);
    }

    #[Test]
    public function blade_flags_after_the_double_dash_boundary_are_forwarded_untouched(): void
    {
        $command = new AnalyzeCommand();

        $this->assertSame(
            ['forwarded' => ['--', '--blade', 'src'], 'blade' => false],
            $command->scanArguments(['psalm-laravel', 'analyze', '--no-blade', '--', '--blade', 'src']),
        );
        $this->assertNull($command->scanArguments(['psalm-laravel', 'analyze', '--', '--blade'])['blade']);
    }

    #[Test]
    public function flags_that_merely_resemble_the_blade_flags_are_forwarded(): void
    {
        $command = new AnalyzeCommand();

        $this->assertSame(
            ['forwarded' => ['--blade=true', '--blades', '--no-blade-x', 'blade'], 'blade' => null],
            $command->scanArguments(['psalm-laravel', 'analyze', '--blade=true', '--blades', '--no-blade-x', 'blade']),
        );
    }

    #[Test]
    public function blade_flags_are_declared_for_help(): void
    {
        $definition = (new AnalyzeCommand())->getDefinition();

        $this->assertTrue($definition->hasOption('blade'));
        $this->assertTrue($definition->hasNegation('no-blade'));
    }

    #[Test]
    public function blade_flag_reaches_the_child_as_the_options_env_and_not_as_an_argument(): void
    {
        $run = $this->runAgainstFakePsalm(['psalm-laravel', 'analyze', '--threads=1', '--blade']);

        $this->assertSame(Command::SUCCESS, $run['exit']);
        $this->assertSame('blade=true', $run['report']['options']);
        $this->assertSame(['--threads=1'], $run['report']['argv']);
    }

    #[Test]
    public function no_blade_flag_reaches_the_child_as_blade_false(): void
    {
        $run = $this->runAgainstFakePsalm(['psalm-laravel', 'analyze', '--no-blade']);

        $this->assertSame('blade=false', $run['report']['options']);
        $this->assertSame([], $run['report']['argv']);
    }

    #[Test]
    public function blade_flag_is_appended_to_an_inherited_options_value_so_the_flag_wins(): void
    {
        \putenv('PSALM_LARAVEL_OPTIONS=foo=1 blade=true');

        $run = $this->runAgainstFakePsalm(['psalm-laravel', 'analyze', '--no-blade']);

        $this->assertSame('foo=1 blade=true blade=false', $run['report']['options']);
    }

    #[Test]
    public function the_child_keeps_the_callers_whole_environment_when_a_blade_flag_is_given(): void
    {
        // proc_open REPLACES the environment when handed an array, so a child that lost PATH or any
        // other inherited variable would mean the handoff built its array from scratch.
        \putenv('PSALM_LARAVEL_TEST_MARKER=kept');

        $run = $this->runAgainstFakePsalm(['psalm-laravel', 'analyze', '--blade']);

        $this->assertTrue($run['report']['path']);
        $this->assertSame('kept', $run['report']['marker']);
    }

    #[Test]
    public function without_a_blade_flag_the_child_environment_is_untouched(): void
    {
        \putenv('PSALM_LARAVEL_TEST_MARKER=kept');

        $run = $this->runAgainstFakePsalm(['psalm-laravel', 'analyze', '--threads=1']);

        $this->assertFalse($run['report']['options'], 'an unset PSALM_LARAVEL_OPTIONS must stay unset');
        $this->assertTrue($run['report']['path']);
        $this->assertSame('kept', $run['report']['marker']);
        $this->assertSame(['--threads=1'], $run['report']['argv']);

        \putenv('PSALM_LARAVEL_OPTIONS=foo=1');

        $run = $this->runAgainstFakePsalm(['psalm-laravel', 'analyze']);

        $this->assertSame('foo=1', $run['report']['options']);
    }

    /**
     * Runs the command against a stand-in `vendor/bin/psalm` that records what it was launched with.
     *
     * @param list<string> $argv
     *
     * @return array{exit: int, report: array{options: string|false, path: bool, marker: string|false, argv: list<string>}}
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
                'path' => getenv('PATH') !== false,
                'marker' => getenv('PSALM_LARAVEL_TEST_MARKER'),
                'argv' => array_slice($argv, 1),
            ]));
            PHP);

        $_SERVER['argv'] = $argv;

        $exit = (new CommandTester(new AnalyzeCommand($this->tempDir)))->execute([]);

        $json = \file_get_contents($binDir . '/report.json');
        $this->assertIsString($json, 'the stand-in psalm never ran');
        /** @var array{options: string|false, path: bool, marker: string|false, argv: list<string>} $report */
        $report = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        return ['exit' => $exit, 'report' => $report];
    }
}
