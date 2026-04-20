<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Cli\AddCommand;
use Psalm\LaravelPlugin\Cli\Integrations\Ci\CiPlan;
use Psalm\LaravelPlugin\Cli\Integrations\Ci\CiTargetInterface;
use Psalm\LaravelPlugin\Cli\Integrations\Ci\CiTargetRegistry;
use Psalm\LaravelPlugin\Cli\Integrations\Ci\GitHubActionsTarget;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(AddCommand::class)]
final class AddCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'psalm-laravel-add-' . \uniqid('', true);
        if (! \mkdir($this->tempDir) && ! \is_dir($this->tempDir)) {
            throw new \RuntimeException(\sprintf('Failed to create temp directory %s', $this->tempDir));
        }
    }

    protected function tearDown(): void
    {
        $this->removeRecursively($this->tempDir);
    }

    #[Test]
    public function writes_workflow_when_target_is_github(): void
    {
        $tester = $this->makeTester();

        $exit = $tester->execute(['target' => 'github']);

        $this->assertSame(Command::SUCCESS, $exit);

        $workflow = $this->workflowPath();
        $this->assertFileExists($workflow);
        $contents = \file_get_contents($workflow);
        $this->assertIsString($contents);
        $this->assertStringContainsString('name: Psalm', $contents);
        $this->assertStringContainsString('types:', $contents);
        $this->assertStringContainsString('security:', $contents);
    }

    #[Test]
    public function writes_workflow_when_target_is_ci_alias(): void
    {
        $tester = $this->makeTester();

        $exit = $tester->execute(['target' => 'ci']);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertFileExists($this->workflowPath());
        // `ci` must resolve through auto-detection to the GitHub adapter;
        // asserting the display name pins the routing rather than trusting
        // "a file was written somewhere" alone.
        $this->assertStringContainsString('GitHub Actions', $tester->getDisplay());
        $contents = \file_get_contents($this->workflowPath());
        $this->assertIsString($contents);
        $this->assertStringContainsString('name: Psalm', $contents);
    }

    #[Test]
    public function dry_run_prints_plan_without_writing(): void
    {
        $tester = $this->makeTester();

        $exit = $tester->execute(['target' => 'github', '--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertFileDoesNotExist($this->workflowPath());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('CREATE', $display);
        $this->assertStringContainsString('Dry run', $display);
    }

    #[Test]
    public function dry_run_reports_update_when_file_exists(): void
    {
        $this->preexistingWorkflow('name: preexisting');

        $tester = $this->makeTester();

        $exit = $tester->execute(['target' => 'github', '--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('UPDATE', $tester->getDisplay());
        // Dry run must not mutate the existing file.
        $this->assertSame('name: preexisting', \file_get_contents($this->workflowPath()));
    }

    #[Test]
    public function refuses_to_overwrite_when_user_answers_no(): void
    {
        $this->preexistingWorkflow('name: preexisting');

        $tester = $this->makeTester();
        $tester->setInputs(['no']);

        $exit = $tester->execute(['target' => 'github']);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertSame('name: preexisting', \file_get_contents($this->workflowPath()));
    }

    #[Test]
    public function overwrites_when_user_answers_yes(): void
    {
        $this->preexistingWorkflow('name: preexisting');

        $tester = $this->makeTester();
        $tester->setInputs(['yes']);

        $exit = $tester->execute(['target' => 'github']);

        $this->assertSame(Command::SUCCESS, $exit);
        $contents = \file_get_contents($this->workflowPath());
        $this->assertIsString($contents);
        $this->assertStringContainsString('name: Psalm', $contents);
    }

    #[Test]
    public function force_overwrites_without_prompting(): void
    {
        $this->preexistingWorkflow('name: preexisting');

        $tester = $this->makeTester();

        $exit = $tester->execute(['target' => 'github', '--force' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
        // Pin the UPDATE/CREATE ternary so inverting it would fail the test.
        $this->assertStringContainsString('UPDATE', $tester->getDisplay());
        $contents = \file_get_contents($this->workflowPath());
        $this->assertIsString($contents);
        $this->assertStringContainsString('name: Psalm', $contents);
    }

    #[Test]
    public function unknown_target_surfaces_clear_error(): void
    {
        $tester = $this->makeTester();

        $exit = $tester->execute(['target' => 'gitlab']);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertFileDoesNotExist($this->workflowPath());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('gitlab', $display);
        $this->assertStringContainsString('github', $display);
    }

    #[Test]
    public function template_read_failure_surfaces_filename(): void
    {
        $missingTemplate = $this->tempDir . \DIRECTORY_SEPARATOR . 'nope.yml';
        $registry = new CiTargetRegistry([new GitHubActionsTarget($missingTemplate)]);
        $tester = $this->makeTester($registry);

        $exit = $tester->execute(['target' => 'github']);

        $this->assertSame(Command::FAILURE, $exit);
        // Assert on the filename (never wrapped) instead of the whole absolute
        // path, whose length can trigger SymfonyStyle's error-box word-wrap.
        $this->assertStringContainsString(\basename($missingTemplate), $tester->getDisplay());
    }

    #[Test]
    public function write_fails_when_parent_cannot_be_created(): void
    {
        // Pre-create a regular file where the `.github` directory would go so
        // mkdir('.github/workflows', true) fails with ENOTDIR (path exists but
        // is not a directory). This is the only branch inside writeAtomically
        // that can fire before the tmp file is created.
        \file_put_contents($this->tempDir . \DIRECTORY_SEPARATOR . '.github', 'not-a-dir');

        $tester = $this->makeTester();

        $exit = $tester->execute(['target' => 'github', '--force' => true]);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('Failed to create directory', $tester->getDisplay());
    }

    #[Test]
    public function write_fails_and_preserves_error_when_tmp_cannot_be_written(): void
    {
        // The only reliable way to make file_put_contents fail without touching
        // the whole process's filesystem access is to chmod the parent dir
        // read-only. Mode bits are a noop on Windows and a no-effect guard
        // when running as root, so skip there rather than false-pass.
        if (\DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX mode bits are required to force file_put_contents failure.');
        }
        if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            $this->markTestSkipped('Running as root bypasses directory write permissions.');
        }

        $workflowsDir = $this->tempDir . \DIRECTORY_SEPARATOR . '.github'
            . \DIRECTORY_SEPARATOR . 'workflows';
        \mkdir($workflowsDir, 0755, true);
        \chmod($workflowsDir, 0555);

        $tester = $this->makeTester();

        try {
            $exit = $tester->execute(['target' => 'github', '--force' => true]);
        } finally {
            // Restore write permission so tearDown's recursive cleanup can run.
            \chmod($workflowsDir, 0755);
        }

        $this->assertSame(Command::FAILURE, $exit);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Failed to write', $display);
        // Pin the "capture suffix before unlink" ordering: if a future refactor
        // inlines @unlink before reading error_get_last(), the OS reason (e.g.
        // "Permission denied") would disappear from the user-facing error.
        $this->assertStringContainsString('Permission denied', $display);
        $this->assertFileDoesNotExist(
            $workflowsDir . \DIRECTORY_SEPARATOR . 'psalm.yml.psalm-laravel.tmp',
        );
    }

    #[Test]
    public function write_cleans_up_tmp_file_when_rename_fails(): void
    {
        // file_put_contents succeeds but rename fails when the destination
        // path already exists as a non-empty directory. This exercises the
        // `rename` failure branch *and* verifies the orphan tmp is removed.
        $workflowsDir = $this->tempDir . \DIRECTORY_SEPARATOR . '.github'
            . \DIRECTORY_SEPARATOR . 'workflows';
        \mkdir($workflowsDir, 0755, true);
        $blockingDir = $workflowsDir . \DIRECTORY_SEPARATOR . 'psalm.yml';
        \mkdir($blockingDir);
        \file_put_contents($blockingDir . \DIRECTORY_SEPARATOR . 'sentinel', 'block');

        $tester = $this->makeTester();

        $exit = $tester->execute(['target' => 'github', '--force' => true]);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('Failed to finalize write', $tester->getDisplay());
        $this->assertFileDoesNotExist($blockingDir . '.psalm-laravel.tmp');
    }

    #[Test]
    public function falls_back_to_getcwd_when_no_working_directory_is_injected(): void
    {
        $originalCwd = \getcwd();
        $this->assertIsString($originalCwd);

        \chdir($this->tempDir);

        try {
            $command = new AddCommand();
            $application = new Application();
            $application->addCommand($command);
            $tester = new CommandTester($application->find('add'));

            $exit = $tester->execute(['target' => 'github', '--force' => true]);

            $this->assertSame(Command::SUCCESS, $exit);
            $this->assertFileExists($this->workflowPath());
        } finally {
            \chdir($originalCwd);
        }
    }

    #[Test]
    public function registry_resolves_across_invocations_with_stub_target(): void
    {
        // Ensures AddCommand doesn't hardcode GitHub and that a fully stubbed
        // target (no bundled template, no real files) still round-trips through
        // the command.
        $stubTarget = new class implements CiTargetInterface {
            #[\Override]
            public function id(): string
            {
                return 'stub';
            }

            #[\Override]
            public function displayName(): string
            {
                return 'Stub CI';
            }

            #[\Override]
            public function detect(string $projectRoot): bool
            {
                return true;
            }

            #[\Override]
            public function plan(string $projectRoot): CiPlan
            {
                $destination = $projectRoot . \DIRECTORY_SEPARATOR . 'stub.yml';
                return new CiPlan($destination, "stub: content\n", \file_exists($destination));
            }
        };

        $tester = $this->makeTester(new CiTargetRegistry([$stubTarget]));

        $exit = $tester->execute(['target' => 'stub']);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertSame("stub: content\n", \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'stub.yml'));
        $this->assertStringContainsString('Stub CI', $tester->getDisplay());
    }

    private function makeTester(?CiTargetRegistry $registry = null): CommandTester
    {
        $command = new AddCommand($registry, $this->tempDir);
        $application = new Application();
        $application->addCommand($command);

        return new CommandTester($application->find('add'));
    }

    private function workflowPath(): string
    {
        return $this->tempDir . \DIRECTORY_SEPARATOR . '.github'
            . \DIRECTORY_SEPARATOR . 'workflows'
            . \DIRECTORY_SEPARATOR . 'psalm.yml';
    }

    private function preexistingWorkflow(string $contents): void
    {
        $dir = $this->tempDir . \DIRECTORY_SEPARATOR . '.github' . \DIRECTORY_SEPARATOR . 'workflows';
        if (! \is_dir($dir)) {
            \mkdir($dir, 0755, true);
        }
        \file_put_contents($dir . \DIRECTORY_SEPARATOR . 'psalm.yml', $contents);
    }

    private function removeRecursively(string $path): void
    {
        if (! \file_exists($path) && ! \is_link($path)) {
            return;
        }

        if (\is_file($path) || \is_link($path)) {
            @\unlink($path);
            return;
        }

        $entries = \scandir($path);
        if ($entries === false) {
            @\rmdir($path);
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeRecursively($path . \DIRECTORY_SEPARATOR . $entry);
        }

        @\rmdir($path);
    }
}
