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

    protected function setUp(): void
    {
        $this->tempDir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'psalm-laravel-analyze-' . \uniqid('', true);
        if (! \mkdir($this->tempDir) && ! \is_dir($this->tempDir)) {
            throw new \RuntimeException(\sprintf('Failed to create temp directory %s', $this->tempDir));
        }
    }

    protected function tearDown(): void
    {
        if (\is_dir($this->tempDir)) {
            @\rmdir($this->tempDir);
        }
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
        $this->assertStringContainsString('Could not find', $tester->getDisplay());
        $this->assertStringContainsString('vendor/bin/psalm', \str_replace(\DIRECTORY_SEPARATOR, '/', $tester->getDisplay()));
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
            $command->forwardedArguments(['psalm-laravel', 'analyze', '--set-baseline=psalm-baseline.xml']),
        );
    }

    #[Test]
    public function forwards_flags_after_the_command_alias(): void
    {
        $command = new AnalyzeCommand();

        $this->assertSame(
            ['--threads=1'],
            $command->forwardedArguments(['psalm-laravel', 'analyse', '--threads=1']),
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
            $command->forwardedArguments(['psalm-laravel', '--set-baseline=psalm-baseline.xml']),
        );
    }

    #[Test]
    public function forwards_multiple_flags_and_path_arguments_verbatim(): void
    {
        $command = new AnalyzeCommand();

        $this->assertSame(
            ['--set-baseline=foo.xml', '--no-cache', 'src'],
            $command->forwardedArguments(['psalm-laravel', 'analyze', '--set-baseline=foo.xml', '--no-cache', 'src']),
        );
    }

    #[Test]
    public function forwards_nothing_when_only_the_command_name_is_present(): void
    {
        $command = new AnalyzeCommand();

        $this->assertSame([], $command->forwardedArguments(['psalm-laravel', 'analyze']));
    }

    #[Test]
    public function reads_the_process_argv_when_no_override_is_given(): void
    {
        // This is the path execute() actually hits — forwardedArguments() with no
        // argument falls back to $_SERVER['argv']. Save/restore so the rest of the
        // suite is unaffected.
        $original = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['psalm-laravel', 'analyze', '--set-baseline=psalm-baseline.xml'];

        try {
            $this->assertSame(['--set-baseline=psalm-baseline.xml'], (new AnalyzeCommand())->forwardedArguments());
        } finally {
            if ($original === null) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $original;
            }
        }
    }

    #[Test]
    public function forwards_nothing_when_the_process_argv_is_unavailable(): void
    {
        // `register_argc_argv = Off` leaves $_SERVER['argv'] unset; the `?? []`
        // fallback must yield an empty list rather than erroring.
        $original = $_SERVER['argv'] ?? null;
        unset($_SERVER['argv']);

        try {
            $this->assertSame([], (new AnalyzeCommand())->forwardedArguments());
        } finally {
            if ($original !== null) {
                $_SERVER['argv'] = $original;
            }
        }
    }
}
