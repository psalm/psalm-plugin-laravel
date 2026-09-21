<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Cli\AnnotateCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(AnnotateCommand::class)]
final class AnnotateCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'psalm-laravel-annotate-cmd-' . \uniqid('', true);

        if (!\mkdir($this->tempDir) && !\is_dir($this->tempDir)) {
            throw new \RuntimeException("Failed to create temp directory {$this->tempDir}");
        }
    }

    protected function tearDown(): void
    {
        @\rmdir($this->tempDir);
    }

    #[Test]
    public function fails_cleanly_when_the_psalm_binary_is_missing(): void
    {
        $application = new Application();
        $application->addCommand(new AnnotateCommand($this->tempDir));

        $tester = new CommandTester($application->find('blade:annotate'));

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('Could not find', $tester->getDisplay());
    }

    #[Test]
    public function forces_single_threaded_analysis(): void
    {
        // Plugin statics collected in a forked worker never reach the parent process, where the
        // AfterAnalysis write runs, so a multi-threaded run would annotate nothing.
        $command = new AnnotateCommand();

        $this->assertSame(['--threads=1'], $command->forwardedArguments(['psalm-laravel', 'blade:annotate']));
    }

    #[Test]
    public function overrides_a_thread_count_the_caller_asked_for(): void
    {
        $command = new AnnotateCommand();

        $this->assertSame(
            ['--threads=1'],
            $command->forwardedArguments(['psalm-laravel', 'blade:annotate', '--threads=8']),
        );
    }

    #[Test]
    public function keeps_its_own_dry_run_flag_out_of_the_forwarded_tokens(): void
    {
        $command = new AnnotateCommand();

        $this->assertSame(
            ['-c', 'psalm.xml', '--threads=1'],
            $command->forwardedArguments(['psalm-laravel', 'blade:annotate', '--dry-run', '-c', 'psalm.xml']),
        );
    }
}
