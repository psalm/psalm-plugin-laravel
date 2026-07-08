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
        if (! \mkdir($this->tempDir) && ! \is_dir($this->tempDir)) {
            throw new \RuntimeException(\sprintf('Failed to create temp directory %s', $this->tempDir));
        }
    }

    protected function tearDown(): void
    {
        $this->removeRecursively($this->tempDir);
    }

    private function removeRecursively(string $path): void
    {
        if (\is_file($path) || \is_link($path)) {
            @\unlink($path);
            return;
        }

        if (! \is_dir($path)) {
            return;
        }

        $entries = @\scandir($path);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $this->removeRecursively($path . \DIRECTORY_SEPARATOR . $entry);
            }
        }

        @\rmdir($path);
    }

    #[Test]
    public function writes_psalm_xml_when_absent(): void
    {
        // Pre-create the conventional ignore targets so the generator emits them.
        // Without these, the dir-existence filter (correctly) skips them.
        \mkdir($this->tempDir . \DIRECTORY_SEPARATOR . 'vendor');
        \mkdir($this->tempDir . \DIRECTORY_SEPARATOR . 'storage');
        \mkdir($this->tempDir . \DIRECTORY_SEPARATOR . 'bootstrap');
        \mkdir($this->tempDir . \DIRECTORY_SEPARATOR . 'bootstrap' . \DIRECTORY_SEPARATOR . 'cache');

        $tester = $this->makeTester();

        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $target = $this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml';
        $this->assertFileExists($target);

        // Check for the plugin FQCN (a stable value), not the surrounding
        // prose, which may reasonably reword.
        $this->assertStringContainsString('Psalm\\LaravelPlugin\\Plugin', $tester->getDisplay());

        $contents = \file_get_contents($target);
        $this->assertIsString($contents);
        $this->assertStringContainsString('errorLevel="4"', $contents);
        $this->assertStringContainsString('findUnusedCode="false"', $contents);
        $this->assertStringContainsString('ensureOverrideAttribute="false"', $contents);
        $this->assertStringContainsString('<pluginClass class="Psalm\\LaravelPlugin\\Plugin"', $contents);
        $this->assertStringContainsString('<directory name="vendor"/>', $contents);
        $this->assertStringContainsString('<directory name="storage"/>', $contents);
        $this->assertStringContainsString('<directory name="bootstrap/cache"/>', $contents);
        $this->assertStringContainsString('<ClassMustBeFinal errorLevel="info"/>', $contents);
        $this->assertStringContainsString('<MissingOverrideAttribute errorLevel="info"/>', $contents);
        $this->assertStringContainsString('<UnnecessaryVarAnnotation errorLevel="suppress"/>', $contents);
    }

    #[Test]
    public function generated_config_omits_run_taint_analysis(): void
    {
        // Regression guard for #1139. On Psalm 6, runTaintAnalysis="true" switches Psalm to a
        // taint-only mode that skips type analysis, so a plain `vendor/bin/psalm` (and the `add`
        // workflow's type job) would silently check no types. Taint runs per-job via the
        // --taint-analysis flag instead. The attribute is valid Psalm 6 config, so the
        // schema-validation test cannot catch a re-add (e.g. from a master merge); asserting the
        // bare token is absent is the only guard, and trips on any form (true or false).
        $tester = $this->makeTester();
        $tester->execute([]);

        $contents = \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        $this->assertIsString($contents);
        $this->assertStringNotContainsString('runTaintAnalysis', $contents);
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

    /**
     * The generated psalm.xml must validate against the *installed* Psalm's
     * config.xsd. Psalm validates its config against that schema on startup and
     * refuses to run if it fails, so a config referencing issue handlers absent
     * from the schema is broken out of the box (#1115: the Psalm 6 line must not
     * emit Psalm-7-only handlers such as MissingPureAnnotation).
     *
     * This is the real re-introduction guard: it runs under the Psalm version
     * pinned in composer.json, so a Psalm-7-only handler creeping back in (e.g.
     * via a master -> 3.x merge) fails here rather than in users' projects.
     */
    #[Test]
    public function generated_config_validates_against_installed_psalm_schema(): void
    {
        $schema = \dirname(__DIR__, 3) . \DIRECTORY_SEPARATOR
            . 'vendor' . \DIRECTORY_SEPARATOR . 'vimeo' . \DIRECTORY_SEPARATOR . 'psalm'
            . \DIRECTORY_SEPARATOR . 'config.xsd';
        // Fail loudly rather than skip: a silent skip would turn this regression
        // guard into dead weight that protects nothing.
        $this->assertFileExists($schema, 'Psalm config.xsd not found; run composer install first.');

        $tester = $this->makeTester();
        $tester->execute([]);

        $contents = \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        $this->assertIsString($contents);

        $previous = \libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument();
            $this->assertTrue($dom->loadXML($contents), 'Generated psalm.xml must be loadable XML.');

            $valid = $dom->schemaValidate($schema);
            $errors = \array_map(
                static fn(\LibXMLError $error): string => \trim($error->message),
                \libxml_get_errors(),
            );
            $this->assertTrue(
                $valid,
                \sprintf("Generated psalm.xml failed config.xsd validation:\n%s", \implode("\n", $errors)),
            );
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
        // Short, tense-tolerant fragment rather than the full pinned sentence —
        // proves the reused/overwritten case is distinguishable from a fresh
        // write without coupling the test to exact copy.
        $this->assertStringContainsStringIgnoringCase('overwrit', $tester->getDisplay());
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
    public function treats_existing_psalm_xml_dist_as_existing_config_and_prompts(): void
    {
        $dist = $this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml.dist';
        \file_put_contents($dist, '<existing-dist/>');

        $tester = $this->makeTester();
        $tester->setInputs(['no']);

        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertSame('<existing-dist/>', \file_get_contents($dist));
        $this->assertFileDoesNotExist($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        $this->assertStringContainsString('psalm.xml.dist already exists', $tester->getDisplay());
    }

    #[Test]
    public function overwrites_psalm_xml_dist_in_place_when_answered_yes(): void
    {
        $dist = $this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml.dist';
        \file_put_contents($dist, '<existing-dist/>');

        $tester = $this->makeTester();
        $tester->setInputs(['yes']);

        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('pluginClass', (string) \file_get_contents($dist));
        $this->assertFileDoesNotExist($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
    }

    #[Test]
    public function force_overwrites_psalm_xml_dist_in_place(): void
    {
        $dist = $this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml.dist';
        \file_put_contents($dist, '<existing-dist/>');

        $tester = $this->makeTester();

        $exit = $tester->execute(['--force' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('pluginClass', (string) \file_get_contents($dist));
        $this->assertFileDoesNotExist($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
    }

    #[Test]
    public function prefers_psalm_xml_over_psalm_xml_dist_when_both_present(): void
    {
        $xml = $this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml';
        $dist = $this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml.dist';
        \file_put_contents($xml, '<existing-xml/>');
        \file_put_contents($dist, '<existing-dist/>');

        $tester = $this->makeTester();

        $exit = $tester->execute(['--force' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('pluginClass', (string) \file_get_contents($xml));
        // psalm.xml.dist must be left untouched: Psalm uses psalm.xml first,
        // so writing the .dist would orphan the generated config.
        $this->assertSame('<existing-dist/>', \file_get_contents($dist));
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
    public function detects_package_autoload_root_from_composer_psr4(): void
    {
        // A Composer package (PSR-4 autoload, no artisan) must scan its autoload
        // root via detectPackageRoots, never the Laravel-app layout — the config
        // path the package-mode install smoke test exercises end-to-end (#1198).
        //
        // Map PSR-4 to a NON-src directory on purpose: detectPackageRoots'
        // last-resort branch only ever emits `src`, so a distinct name proves the
        // composer-autoload extraction actually ran. A regressed extractor would
        // fall through to the app-layout fallback, failing the `lib` assertion,
        // rather than being silently rescued by the src fallback.
        \file_put_contents(
            $this->tempDir . \DIRECTORY_SEPARATOR . 'composer.json',
            (string) \json_encode(['autoload' => ['psr-4' => ['Acme\\Widget\\' => 'lib/']]]),
        );
        \mkdir($this->tempDir . \DIRECTORY_SEPARATOR . 'lib');

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $contents = (string) \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        $this->assertStringContainsString('<directory name="lib"/>', $contents);
        $this->assertStringNotContainsString('<file name="artisan"/>', $contents);
        $this->assertStringNotContainsString('<directory name="app"/>', $contents);
    }

    #[Test]
    public function monorepo_package_source_is_not_swallowed_by_the_packages_ignore(): void
    {
        // Monorepo layout: PSR-4 source lives under packages/*/src. 'packages' is
        // no longer an ignore candidate, so it must not appear in <ignoreFiles>;
        // otherwise it would remove the whole source tree from ANALYSIS (Psalm
        // still scans it for reflection) — a silent zero-issue run. Genuine
        // ignores like vendor/ are still emitted. See #1213.
        \file_put_contents(
            $this->tempDir . \DIRECTORY_SEPARATOR . 'composer.json',
            (string) \json_encode(['autoload' => ['psr-4' => ['Acme\\Core\\' => 'packages/core/src']]]),
        );
        \mkdir($this->tempDir . \DIRECTORY_SEPARATOR . 'packages' . \DIRECTORY_SEPARATOR . 'core' . \DIRECTORY_SEPARATOR . 'src', 0o777, true);
        \mkdir($this->tempDir . \DIRECTORY_SEPARATOR . 'vendor');

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $contents = (string) \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        $this->assertStringContainsString('<directory name="packages/core/src"/>', $contents);
        // The overlapping ignore is dropped; the non-overlapping one survives.
        $this->assertStringNotContainsString('<directory name="packages"/>', $contents);
        $this->assertStringContainsString('<directory name="vendor"/>', $contents);
    }

    #[Test]
    public function detects_laravel_app_layout_when_artisan_present(): void
    {
        // Presence of artisan selects the Laravel-app branch (detectLaravelAppRoots):
        // conventional app dirs and entry files are scanned, so the discriminator the
        // smoke test relies on (artisan/app present => app layout) is exercised here.
        \file_put_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'artisan', "#!/usr/bin/env php\n");
        \mkdir($this->tempDir . \DIRECTORY_SEPARATOR . 'app');

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $contents = (string) \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        $this->assertStringContainsString('<directory name="app"/>', $contents);
        $this->assertStringContainsString('<file name="artisan"/>', $contents);
        // Only on-disk dirs are emitted by detectLaravelAppRoots; bootstrap/ was not
        // created. If the app branch regressed to empty, detectSourceRoots' ultimate
        // fallback would emit ALL app dirs (including bootstrap), so this negative
        // proves the present-only real branch ran rather than the fallback.
        $this->assertStringNotContainsString('<directory name="bootstrap"/>', $contents);
    }

    #[Test]
    public function falls_back_to_src_directory_for_package_without_psr4(): void
    {
        // No artisan and no PSR-4 mapping: detectPackageRoots' last-resort branch
        // scans src/ when present, so even a minimal package gets a valid config.
        \mkdir($this->tempDir . \DIRECTORY_SEPARATOR . 'src');

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $contents = (string) \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        $this->assertStringContainsString('<directory name="src"/>', $contents);
        $this->assertStringNotContainsString('<file name="artisan"/>', $contents);
    }

    private function makeTester(): CommandTester
    {
        $command = new InitCommand($this->tempDir);
        $application = new Application();
        $application->addCommand($command);

        return new CommandTester($application->find('init'));
    }
}
