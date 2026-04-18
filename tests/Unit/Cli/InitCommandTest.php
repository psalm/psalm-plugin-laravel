<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Cli\InitCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(InitCommand::class)]
final class InitCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'psalm-laravel-init-' . \uniqid('', true);
        \mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $target = $this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml';
        if (\file_exists($target)) {
            \unlink($target);
        }

        \rmdir($this->tempDir);
    }

    #[Test]
    public function writes_psalm_xml_when_absent(): void
    {
        $tester = $this->makeTester();

        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $target = $this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml';
        $this->assertFileExists($target);

        $contents = \file_get_contents($target);
        $this->assertIsString($contents);
        $this->assertStringContainsString('errorLevel="3"', $contents);
        $this->assertStringContainsString('findUnusedCode="false"', $contents);
        $this->assertStringContainsString('ensureOverrideAttribute="false"', $contents);
        $this->assertStringContainsString('<pluginClass class="Psalm\\LaravelPlugin\\Plugin"/>', $contents);
        $this->assertStringContainsString('<directory name="vendor"/>', $contents);
        $this->assertStringContainsString('<directory name="storage"/>', $contents);
        $this->assertStringContainsString('<directory name="bootstrap/cache"/>', $contents);
    }

    #[Test]
    public function generated_xml_is_well_formed(): void
    {
        $tester = $this->makeTester();
        $tester->execute([]);

        $target = $this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml';
        $contents = \file_get_contents($target);
        $this->assertIsString($contents);

        $previous = \libxml_use_internal_errors(true);
        try {
            $xml = \simplexml_load_string($contents);
            $this->assertNotFalse($xml, 'Generated psalm.xml must be well-formed XML.');
            $this->assertSame('psalm', $xml->getName());
        } finally {
            \libxml_clear_errors();
            \libxml_use_internal_errors($previous);
        }
    }

    #[Test]
    public function refuses_to_overwrite_by_default_when_answered_no(): void
    {
        $target = $this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml';
        \file_put_contents($target, '<existing/>');

        $tester = $this->makeTester();
        $tester->setInputs(['no']);

        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertSame('<existing/>', \file_get_contents($target));
    }

    #[Test]
    public function overwrites_when_answered_yes(): void
    {
        $target = $this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml';
        \file_put_contents($target, '<existing/>');

        $tester = $this->makeTester();
        $tester->setInputs(['yes']);

        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('pluginClass', (string) \file_get_contents($target));
    }

    #[Test]
    public function overwrites_without_prompt_with_force(): void
    {
        $target = $this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml';
        \file_put_contents($target, '<existing/>');

        $tester = $this->makeTester();

        $exit = $tester->execute(['--force' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('pluginClass', (string) \file_get_contents($target));
    }

    /**
     * Exercises the production code path where no workingDirectory is injected —
     * the command must fall back to getcwd(). We chdir() into the temp dir so
     * the fallback lands somewhere we can clean up.
     */
    #[Test]
    public function falls_back_to_getcwd_when_no_working_directory_is_injected(): void
    {
        $originalCwd = \getcwd();
        $this->assertIsString($originalCwd);

        \chdir($this->tempDir);

        try {
            $command = new InitCommand();
            $application = new Application();
            $application->add($command);
            $tester = new CommandTester($application->find('init'));

            $exit = $tester->execute([]);

            $this->assertSame(Command::SUCCESS, $exit);
            $this->assertFileExists($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        } finally {
            \chdir($originalCwd);
        }
    }

    private function makeTester(): CommandTester
    {
        $command = new InitCommand($this->tempDir);
        $application = new Application();
        $application->add($command);

        return new CommandTester($application->find('init'));
    }
}
