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
        if (! \mkdir($this->tempDir, 0777, true) && ! \is_dir($this->tempDir)) {
            throw new \RuntimeException(\sprintf('Failed to create temp directory %s', $this->tempDir));
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
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
        $this->assertStringContainsString('<ClassMustBeFinal errorLevel="suppress"/>', $contents);
        $this->assertStringContainsString('<MissingOverrideAttribute errorLevel="suppress"/>', $contents);
        $this->assertStringContainsString('<UnnecessaryVarAnnotation errorLevel="suppress"/>', $contents);
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

    #[Test]
    public function writes_custom_error_level(): void
    {
        $tester = $this->makeTester();

        $exit = $tester->execute(['--level' => '1']);

        $this->assertSame(Command::SUCCESS, $exit);
        $target = $this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml';
        $this->assertStringContainsString('errorLevel="1"', (string) \file_get_contents($target));
    }

    #[Test]
    public function rejects_invalid_error_level(): void
    {
        $tester = $this->makeTester();

        $exit = $tester->execute(['--level' => '9']);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertFileDoesNotExist($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        $this->assertStringContainsString('Invalid --level', $tester->getDisplay());
    }

    #[Test]
    public function accepts_preset_auto(): void
    {
        $tester = $this->makeTester();

        $exit = $tester->execute(['--preset' => 'auto']);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertFileExists($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
    }

    #[Test]
    public function auto_is_the_default_preset(): void
    {
        $tester = $this->makeTester();

        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertFileExists($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
    }

    #[Test]
    public function rejects_unknown_preset(): void
    {
        $tester = $this->makeTester();

        $exit = $tester->execute(['--preset' => 'ci']);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertFileDoesNotExist($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        $this->assertStringContainsString("Invalid --preset value 'ci'", $tester->getDisplay());
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
            $application->addCommand($command);
            $tester = new CommandTester($application->find('init'));

            $exit = $tester->execute([]);

            $this->assertSame(Command::SUCCESS, $exit);
            $this->assertFileExists($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        } finally {
            \chdir($originalCwd);
        }
    }

    #[Test]
    public function includes_phpunit_plugin_when_dependency_and_plugin_both_installed(): void
    {
        $this->writeComposerJson(['require-dev' => ['phpunit/phpunit' => '^11.0']]);
        $this->writeVendorPackage('psalm/phpunit-plugin');

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $contents = (string) \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        $this->assertStringContainsString('<pluginClass class="Psalm\\PhpUnitPlugin\\Plugin"/>', $contents);
        $this->assertStringContainsString('PHPUnit detected and psalm/phpunit-plugin already installed', $tester->getDisplay());
    }

    #[Test]
    public function includes_both_companion_plugins_when_both_present(): void
    {
        $this->writeComposerJson([
            'require-dev' => [
                'phpunit/phpunit' => '^11.0',
                'mockery/mockery' => '^1.6',
            ],
        ]);
        $this->writeVendorPackage('psalm/phpunit-plugin');
        $this->writeVendorPackage('psalm/mockery-plugin');

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $contents = (string) \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        $this->assertStringContainsString('<pluginClass class="Psalm\\PhpUnitPlugin\\Plugin"/>', $contents);
        $this->assertStringContainsString('<pluginClass class="Psalm\\MockeryPlugin\\Plugin"/>', $contents);
    }

    #[Test]
    public function omits_companion_plugin_when_dependency_missing(): void
    {
        // Plugin installed but dep not declared → do not enable
        // (avoids enabling a plugin for a framework the project isn't using).
        $this->writeComposerJson(['require' => ['illuminate/support' => '^12.0']]);
        $this->writeVendorPackage('psalm/phpunit-plugin');

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $contents = (string) \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        $this->assertStringNotContainsString('PhpUnitPlugin', $contents);
    }

    #[Test]
    public function prints_hint_in_non_interactive_mode_when_plugin_missing(): void
    {
        $this->writeComposerJson(['require-dev' => ['phpunit/phpunit' => '^11.0']]);
        // Intentionally: no vendor/psalm/phpunit-plugin/ — Case B.

        $tester = $this->makeTester();
        $exit = $tester->execute([], ['interactive' => false]);

        $this->assertSame(Command::SUCCESS, $exit);
        $contents = (string) \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        $this->assertStringNotContainsString('PhpUnitPlugin', $contents);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('PHPUnit detected', $display);
        $this->assertStringContainsString('composer require --dev psalm/phpunit-plugin', $display);
    }

    #[Test]
    public function declined_interactive_prompt_falls_back_to_hint(): void
    {
        $this->writeComposerJson(['require-dev' => ['phpunit/phpunit' => '^11.0']]);

        $tester = $this->makeTester();
        // Decline the companion prompt. No composer invocation, no XML change.
        $tester->setInputs(['no']);

        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $contents = (string) \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        $this->assertStringNotContainsString('PhpUnitPlugin', $contents);
        $this->assertStringContainsString('composer require --dev psalm/phpunit-plugin', $tester->getDisplay());
    }

    #[Test]
    public function no_suggest_skips_all_companion_detection(): void
    {
        // Even when both dep and plugin are installed, --no-suggest must not
        // auto-enable or even detect. The output stays clean.
        $this->writeComposerJson(['require-dev' => ['phpunit/phpunit' => '^11.0']]);
        $this->writeVendorPackage('psalm/phpunit-plugin');

        $tester = $this->makeTester();
        $exit = $tester->execute(['--no-suggest' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
        $contents = (string) \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        $this->assertStringNotContainsString('PhpUnitPlugin', $contents);
        $this->assertStringNotContainsString('PHPUnit detected', $tester->getDisplay());
    }

    #[Test]
    public function companion_xml_remains_well_formed_with_all_plugin_entries(): void
    {
        $this->writeComposerJson([
            'require-dev' => [
                'phpunit/phpunit' => '^11.0',
                'mockery/mockery' => '^1.6',
            ],
        ]);
        $this->writeVendorPackage('psalm/phpunit-plugin');
        $this->writeVendorPackage('psalm/mockery-plugin');

        $tester = $this->makeTester();
        $tester->execute([]);

        $contents = (string) \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');

        $previous = \libxml_use_internal_errors(true);
        try {
            $xml = \simplexml_load_string($contents);
            $this->assertNotFalse($xml, 'psalm.xml with companion plugins must remain well-formed.');

            // All three pluginClass entries must live directly under <plugins>.
            // Use canonicalizing equality so the assertion survives any future
            // reordering of CompanionPlugin::cases().
            $classes = [];
            foreach ($xml->plugins->pluginClass as $entry) {
                $classes[] = (string) $entry['class'];
            }

            $this->assertEqualsCanonicalizing(
                [
                    'Psalm\\LaravelPlugin\\Plugin',
                    'Psalm\\PhpUnitPlugin\\Plugin',
                    'Psalm\\MockeryPlugin\\Plugin',
                ],
                $classes,
            );
            // The Laravel plugin itself always comes first (the template puts
            // it before the companion placeholder).
            $this->assertSame('Psalm\\LaravelPlugin\\Plugin', $classes[0]);
        } finally {
            \libxml_clear_errors();
            \libxml_use_internal_errors($previous);
        }
    }

    #[Test]
    public function includes_mockery_plugin_in_isolation(): void
    {
        $this->writeComposerJson(['require-dev' => ['mockery/mockery' => '^1.6']]);
        $this->writeVendorPackage('psalm/mockery-plugin');

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $contents = (string) \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        $this->assertStringContainsString('<pluginClass class="Psalm\\MockeryPlugin\\Plugin"/>', $contents);
        $this->assertStringNotContainsString('PhpUnitPlugin', $contents);
        $this->assertStringContainsString('Mockery detected and psalm/mockery-plugin already installed', $tester->getDisplay());
    }

    #[Test]
    public function no_suggest_also_suppresses_hints_when_plugin_missing(): void
    {
        // Complements no_suggest_skips_all_companion_detection: here the
        // companion plugin is NOT installed, so without --no-suggest we'd
        // print a hint. The flag must silence that too.
        $this->writeComposerJson(['require-dev' => ['phpunit/phpunit' => '^11.0']]);
        // Intentionally no vendor/psalm/phpunit-plugin/.

        $tester = $this->makeTester();
        $exit = $tester->execute(['--no-suggest' => true], ['interactive' => false]);

        $this->assertSame(Command::SUCCESS, $exit);
        $display = $tester->getDisplay();
        $this->assertStringNotContainsString('PHPUnit detected', $display);
        $this->assertStringNotContainsString('composer require --dev', $display);
    }

    #[Test]
    public function surfaces_malformed_composer_json_and_skips_detection(): void
    {
        \file_put_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'composer.json', '{ broken json');

        $tester = $this->makeTester();
        $exit = $tester->execute([], ['interactive' => false]);

        $this->assertSame(Command::SUCCESS, $exit);
        // psalm.xml is still written, so a broken composer.json doesn't block init.
        $this->assertFileExists($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        // But the user is told why no companion detection ran.
        $this->assertStringContainsString(
            'Skipping companion-plugin detection',
            $tester->getDisplay(),
        );
    }

    private function makeTester(): CommandTester
    {
        $command = new InitCommand($this->tempDir);
        $application = new Application();
        $application->addCommand($command);

        return new CommandTester($application->find('init'));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeComposerJson(array $data): void
    {
        \file_put_contents(
            $this->tempDir . \DIRECTORY_SEPARATOR . 'composer.json',
            \json_encode($data, \JSON_THROW_ON_ERROR),
        );
    }

    private function writeVendorPackage(string $package): void
    {
        $dir = $this->tempDir . \DIRECTORY_SEPARATOR . 'vendor'
            . \DIRECTORY_SEPARATOR . \str_replace('/', \DIRECTORY_SEPARATOR, $package);
        \mkdir($dir, 0777, true);
        \file_put_contents(
            $dir . \DIRECTORY_SEPARATOR . 'composer.json',
            \json_encode(['name' => $package], \JSON_THROW_ON_ERROR),
        );
    }

    private function removeDirectory(string $path): void
    {
        if (! \is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            $this->assertInstanceOf(\SplFileInfo::class, $fileInfo);
            if ($fileInfo->isDir()) {
                @\rmdir($fileInfo->getPathname());
            } else {
                @\unlink($fileInfo->getPathname());
            }
        }

        @\rmdir($path);
    }
}
