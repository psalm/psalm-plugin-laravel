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
        $application->addCommand(new AnnotateCommand($this->tempDir, ['psalm-laravel', 'blade:annotate']));

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
            ['--threads=1', '-c', 'psalm.xml'],
            $command->forwardedArguments(['psalm-laravel', 'blade:annotate', '--dry-run', '-c', 'psalm.xml']),
        );
    }

    #[Test]
    public function puts_the_forced_thread_count_before_the_forwarded_arguments(): void
    {
        // PHP's getopt() stops at the first positional argument, so a --threads=1 appended after one
        // is never parsed: the child forks, and the pass then refuses to write anything.
        $command = new AnnotateCommand();

        $this->assertSame(
            ['--threads=1', '-c', 'psalm.xml'],
            $command->forwardedArguments(['psalm-laravel', 'blade:annotate', '-c', 'psalm.xml']),
        );
    }

    #[Test]
    public function refuses_a_run_limited_to_some_paths(): void
    {
        // Producer agreement is a whole-project claim: a competing call site in a file the run never
        // analysed would leave a concrete type written for a variable two call sites disagree on.
        $application = new Application();
        $application->addCommand(new AnnotateCommand($this->tempDir, ['psalm-laravel', 'blade:annotate', 'app/Renderer.php']));

        $tester = new CommandTester($application->find('blade:annotate'));

        $this->assertSame(Command::FAILURE, $tester->execute(['psalm-args' => ['app/Renderer.php']]));
        $this->assertStringContainsString('whole project', $tester->getDisplay());
    }

    #[Test]
    public function names_the_path_limiting_arguments_it_refuses(): void
    {
        $command = new AnnotateCommand();

        $this->assertSame(
            ['-f', 'app/A.php'],
            $command->pathLimitingArguments(['psalm-laravel', 'blade:annotate', '-f', 'app/A.php']),
        );
        $this->assertSame(
            ['app/'],
            $command->pathLimitingArguments(['psalm-laravel', 'blade:annotate', 'app/']),
        );
        $this->assertSame(
            [],
            $command->pathLimitingArguments(['psalm-laravel', 'blade:annotate', '-c', 'psalm.xml', '--dry-run']),
        );
    }
}
