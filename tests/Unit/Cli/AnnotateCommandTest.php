<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Cli\AnnotateCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
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
        foreach (\glob($this->tempDir . \DIRECTORY_SEPARATOR . '*') ?: [] as $entry) {
            @\unlink($entry);
        }

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

    /**
     * @param list<string> $overrideArguments
     */
    #[Test]
    #[DataProvider('overrideFlags')]
    public function rejects_per_run_overrides_without_launching_psalm(array $overrideArguments, string $named): void
    {
        // The flag must reach neither psalm (which dies on it) nor the path check (where `x=y` would be a path).
        $psalmBin = $this->tempDir . '/vendor/bin/psalm';
        \mkdir(\dirname($psalmBin), 0o777, true);
        \file_put_contents($psalmBin, "<?php\nfile_put_contents(__DIR__ . '/launched', '1');\n");

        $argv = ['psalm-laravel', 'blade:annotate', ...$overrideArguments];
        $application = new Application();
        $application->addCommand(new AnnotateCommand($this->tempDir, $argv));

        $tester = new CommandTester($application->find('blade:annotate'));

        try {
            $this->assertSame(Command::FAILURE, $tester->execute([]));
            $this->assertStringContainsString('does not accept per-run plugin overrides', $tester->getDisplay());
            $this->assertStringContainsString($named, $tester->getDisplay());
            $this->assertFileDoesNotExist(\dirname($psalmBin) . '/launched');
        } finally {
            @\unlink(\dirname($psalmBin) . '/launched');
            @\unlink($psalmBin);
            @\rmdir(\dirname($psalmBin));
            @\rmdir(\dirname($psalmBin, 2));
        }
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function overrideFlags(): iterable
    {
        yield 'spaced option' => [['--plugin-option', 'x=y'], '--plugin-option'];
        yield 'equals option' => [['--plugin-option=x=y'], '--plugin-option'];
        yield '--blade' => [['--blade'], '--blade'];
        yield '--no-blade' => [['-c', 'psalm.xml', '--no-blade'], '--no-blade'];
    }

    #[Test]
    public function an_override_spelled_after_the_options_terminator_is_a_plain_token(): void
    {
        $command = new AnnotateCommand();

        $this->assertSame([], $command->rejectedOverrides(['psalm-laravel', 'blade:annotate', '--', '--blade']));
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

    #[Test]
    public function takes_a_long_option_whose_value_is_spelled_as_a_separate_token(): void
    {
        // Psalm's getopt() accepts both `--config=psalm.xml` and `--config psalm.xml`; refusing the
        // second form would reject every value-taking flag the user is entitled to pass.
        $command = new AnnotateCommand();

        $this->assertSame(
            [],
            $command->pathLimitingArguments([
                'psalm-laravel',
                'blade:annotate',
                '--config',
                'psalm.xml',
                '--memory-limit',
                '2G',
                '--root',
                '/srv/app',
                '--error-level',
                '3',
                '--output-format',
                'compact',
            ]),
        );
    }

    #[Test]
    public function still_refuses_a_path_that_follows_a_long_option(): void
    {
        $command = new AnnotateCommand();

        $this->assertSame(
            ['app/Renderer.php'],
            $command->pathLimitingArguments(['psalm-laravel', 'blade:annotate', '--no-progress', 'app/Renderer.php']),
        );
        // getopt binds an OPTIONAL value only when it is attached, so the next token is a path.
        $this->assertSame(
            ['app/Renderer.php'],
            $command->pathLimitingArguments(['psalm-laravel', 'blade:annotate', '--find-unused-code', 'app/Renderer.php']),
        );
    }

    #[Test]
    public function a_dry_run_that_could_not_plan_every_template_fails(): void
    {
        // The diff is incomplete, so the caller must not read exit 0 as "this is what writing does".
        $this->assertSame(Command::FAILURE, $this->reportExitCode([
            'dryRun' => true,
            'changed' => ['a.blade.php' => ['title']],
            'failures' => ['b.blade.php' => 'unreadable'],
            'diff' => "+{{-- @var string \$title --}}\n",
        ]));
    }

    #[Test]
    public function a_write_that_could_not_annotate_every_template_fails(): void
    {
        $this->assertSame(Command::FAILURE, $this->reportExitCode([
            'dryRun' => false,
            'changed' => ['a.blade.php' => ['title']],
            'failures' => ['b.blade.php' => 'unreadable'],
        ]));
    }

    #[Test]
    public function a_run_that_annotated_every_template_succeeds_in_either_mode(): void
    {
        foreach ([true, false] as $dryRun) {
            $this->assertSame(Command::SUCCESS, $this->reportExitCode([
                'dryRun' => $dryRun,
                'changed' => ['a.blade.php' => ['title']],
                'failures' => [],
                'diff' => "+{{-- @var string \$title --}}\n",
            ]));
        }
    }

    /**
     * Drives the reporting half of the command directly: the exit code depends on what the child
     * published, and no CommandTester run can produce a child.
     *
     * @param array<string, mixed> $published
     */
    private function reportExitCode(array $published): int
    {
        $controlFile = $this->tempDir . \DIRECTORY_SEPARATOR . 'control.json';
        \file_put_contents($controlFile, (string) \json_encode($published));

        $command = new AnnotateCommand();
        $report = new \ReflectionMethod($command, 'report');
        $io = new SymfonyStyle(new ArrayInput([]), new NullOutput());

        /** @var int */
        return $report->invoke($command, $io, $controlFile, 0);
    }
}
