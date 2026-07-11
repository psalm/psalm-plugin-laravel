<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Cli\InitCommand;
use Psalm\LaravelPlugin\Cli\SourceRootCandidate;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(InitCommand::class)]
#[CoversClass(SourceRootCandidate::class)]
final class InitCommandTest extends TestCase
{
    private string $tempDir;

    /** @var list<string> */
    private array $externalPaths = [];

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
        foreach ($this->externalPaths as $path) {
            $this->removeRecursively($path);
        }
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

    #[Test]
    public function monorepo_app_layout_adds_nested_package_source_roots(): void
    {
        // #1224: a Webkul-stack monorepo (bagisto, unopim) ships artisan, so init picks
        // the Laravel-app branch. Its packages/*/*/src source (mapped in root composer
        // autoload) must now surface in <projectFiles> alongside the app dirs.
        \file_put_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'artisan', "#!/usr/bin/env php\n");
        \mkdir($this->tempDir . \DIRECTORY_SEPARATOR . 'app');
        \file_put_contents(
            $this->tempDir . \DIRECTORY_SEPARATOR . 'composer.json',
            (string) \json_encode(['autoload' => ['psr-4' => [
                'App\\' => 'app/',
                'Webkul\\Admin\\' => 'packages/Webkul/Admin/src',
                'Webkul\\Core\\' => 'packages/Webkul/Core/src',
            ]]]),
        );
        $this->makeDir('packages/Webkul/Admin/src');
        $this->makeDir('packages/Webkul/Core/src');

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $contents = (string) \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        $this->assertStringContainsString('<directory name="app"/>', $contents);
        $this->assertStringContainsString('<file name="artisan"/>', $contents);
        $this->assertStringContainsString('<directory name="packages/Webkul/Admin/src"/>', $contents);
        $this->assertStringContainsString('<directory name="packages/Webkul/Core/src"/>', $contents);
        // Exact src roots, never the whole packages/ tree (which would pull in vendor/).
        $this->assertStringNotContainsString('<directory name="packages"/>', $contents);
    }

    #[Test]
    public function canonicalises_dot_segments_in_composer_paths(): void
    {
        // Paths are canonicalised once: `./packages/x` and `app/../packages/x` emit as
        // clean `packages/x`, keeping output tidy and the packages/ warning (which
        // matches on canonical paths) correct. See #1224.
        \file_put_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'artisan', "#!/usr/bin/env php\n");
        \mkdir($this->tempDir . \DIRECTORY_SEPARATOR . 'app');
        \file_put_contents(
            $this->tempDir . \DIRECTORY_SEPARATOR . 'composer.json',
            (string) \json_encode(['autoload' => ['psr-4' => [
                'A\\' => './packages/one/src',
                'B\\' => 'app/../packages/two/src',
            ]]]),
        );
        $this->makeDir('packages/one/src');
        $this->makeDir('packages/two/src');

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $contents = (string) \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        $this->assertStringContainsString('<directory name="packages/one/src"/>', $contents);
        $this->assertStringContainsString('<directory name="packages/two/src"/>', $contents);
        $this->assertStringNotContainsString('name="./packages', $contents);
        $this->assertStringNotContainsString('/../', $contents);
    }

    #[Test]
    public function empty_composer_root_emits_the_project_root(): void
    {
        \file_put_contents(
            $this->tempDir . \DIRECTORY_SEPARATOR . 'composer.json',
            (string) \json_encode(['autoload' => ['psr-4' => ['Acme\\' => '']]]),
        );

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $contents = (string) \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        $this->assertSame(1, \substr_count($contents, '<directory name="."/>'));
    }

    #[Test]
    public function dot_composer_root_emits_the_project_root(): void
    {
        \file_put_contents(
            $this->tempDir . \DIRECTORY_SEPARATOR . 'composer.json',
            (string) \json_encode(['autoload' => ['psr-4' => ['Acme\\' => '.']]]),
        );

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $contents = (string) \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        $this->assertSame(1, \substr_count($contents, '<directory name="."/>'));
    }

    #[Test]
    public function project_root_mapping_ignores_tests_without_phpunit_plugin(): void
    {
        $this->makeDir('tests');
        \file_put_contents(
            $this->tempDir . \DIRECTORY_SEPARATOR . 'composer.json',
            (string) \json_encode(['autoload' => ['psr-4' => ['Acme\\' => '.']]]),
        );

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $contents = (string) \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        $this->assertStringContainsString('<directory name="."/>', $contents);
        $this->assertStringContainsString('<ignoreFiles allowMissingFiles="true">', $contents);
        $this->assertStringContainsString('<directory name="tests"/>', $contents);
        $this->assertStringContainsString('tests/ dir skipped', $tester->getDisplay());
    }

    #[Test]
    public function project_root_mapping_scans_tests_with_phpunit_plugin(): void
    {
        $this->makeDir('tests');
        \file_put_contents(
            $this->tempDir . \DIRECTORY_SEPARATOR . 'composer.json',
            (string) \json_encode([
                'autoload' => ['psr-4' => ['Acme\\' => '.']],
                'require-dev' => ['psalm/plugin-phpunit' => '^0.1'],
            ]),
        );

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $contents = (string) \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        $this->assertStringContainsString('<directory name="."/>', $contents);
        $this->assertStringContainsString('<directory name="tests"/>', $contents);
        $this->assertStringNotContainsString('<ignoreFiles allowMissingFiles="true">', $contents);
        $this->assertStringNotContainsString('tests/ dir skipped', $tester->getDisplay());
    }

    #[Test]
    public function project_root_mapping_counts_as_packages_coverage(): void
    {
        $this->makeDir('packages/acme/src');
        \file_put_contents(
            $this->tempDir . \DIRECTORY_SEPARATOR . 'composer.json',
            (string) \json_encode(['autoload' => ['psr-4' => ['Acme\\' => '.']]]),
        );

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringNotContainsString(
            'packages/ exists but no source root under it is scanned',
            $tester->getDisplay(),
        );
    }

    #[Test]
    public function absolute_composer_root_inside_project_is_emitted_relative(): void
    {
        $this->makeDir('lib');
        $absoluteRoot = $this->tempDir . \DIRECTORY_SEPARATOR . 'lib';
        \file_put_contents(
            $this->tempDir . \DIRECTORY_SEPARATOR . 'composer.json',
            (string) \json_encode(['autoload' => ['psr-4' => ['Acme\\' => $absoluteRoot]]]),
        );

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $contents = (string) \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        $this->assertStringContainsString('<directory name="lib"/>', $contents);
        $this->assertStringNotContainsString(\htmlspecialchars($absoluteRoot, \ENT_QUOTES | \ENT_XML1, 'UTF-8'), $contents);
    }

    #[Test]
    public function absolute_composer_root_outside_project_stays_canonical_and_valid_xml(): void
    {
        $outside = $this->makeExternalDir('Outside & Source');
        \file_put_contents(
            $this->tempDir . \DIRECTORY_SEPARATOR . 'composer.json',
            (string) \json_encode(['autoload' => ['psr-4' => ['Acme\\' => $outside]]]),
        );

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $contents = (string) \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        $escapedOutside = \htmlspecialchars((string) \realpath($outside), \ENT_QUOTES | \ENT_XML1, 'UTF-8');
        $this->assertStringContainsString('<directory name="' . $escapedOutside . '"/>', $contents);
        $this->assertNotFalse(\simplexml_load_string($contents));
    }

    #[Test]
    public function package_src_fallback_is_not_suppressed_by_config(): void
    {
        $this->makeDir('config');
        $this->makeDir('src');
        \file_put_contents(
            $this->tempDir . \DIRECTORY_SEPARATOR . 'composer.json',
            (string) \json_encode(['autoload' => ['psr-4' => ['Acme\\' => 'missing']]]),
        );

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $contents = (string) \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        $configPosition = \strpos($contents, '<directory name="config"/>');
        $srcPosition = \strpos($contents, '<directory name="src"/>');
        $this->assertIsInt($configPosition);
        $this->assertIsInt($srcPosition);
        $this->assertLessThan($srcPosition, $configPosition);
    }

    #[Test]
    public function composer_roots_are_exactly_deduplicated_by_canonical_path(): void
    {
        $this->makeDir('src');
        \file_put_contents(
            $this->tempDir . \DIRECTORY_SEPARATOR . 'composer.json',
            (string) \json_encode(['autoload' => ['psr-4' => ['Acme\\' => ['src', './src/']]]]),
        );

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $contents = (string) \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        $this->assertSame(1, \substr_count($contents, '<directory name="src"/>'));
    }

    #[Test]
    public function composer_alias_is_deduplicated_against_conventional_root(): void
    {
        \file_put_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'artisan', "#!/usr/bin/env php\n");
        $this->makeDir('app');
        $this->makeSymlink(
            $this->tempDir . \DIRECTORY_SEPARATOR . 'app',
            $this->tempDir . \DIRECTORY_SEPARATOR . 'source-alias',
        );
        \file_put_contents(
            $this->tempDir . \DIRECTORY_SEPARATOR . 'composer.json',
            (string) \json_encode(['autoload' => ['psr-4' => ['App\\' => 'source-alias']]]),
        );

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $contents = (string) \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        $this->assertSame(1, \substr_count($contents, '<directory name="app"/>'));
        $this->assertStringNotContainsString('source-alias', $contents);
    }

    #[Test]
    public function symlink_followed_by_parent_segment_uses_filesystem_resolution(): void
    {
        $outside = $this->makeExternalDir('symlink-parent');
        $nested = $outside . \DIRECTORY_SEPARATOR . 'nested';
        \mkdir($nested);
        $this->makeSymlink($nested, $this->tempDir . \DIRECTORY_SEPARATOR . 'linked');
        \file_put_contents(
            $this->tempDir . \DIRECTORY_SEPARATOR . 'composer.json',
            (string) \json_encode(['autoload' => ['psr-4' => ['Acme\\' => 'linked/..']]]),
        );

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $contents = (string) \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        $escapedOutside = \htmlspecialchars((string) \realpath($outside), \ENT_QUOTES | \ENT_XML1, 'UTF-8');
        $this->assertStringContainsString('<directory name="' . $escapedOutside . '"/>', $contents);
        $this->assertStringNotContainsString('<directory name="."/>', $contents);
    }

    #[Test]
    public function package_warning_uses_canonical_path_reached_through_symlink(): void
    {
        $this->makeDir('packages/acme/src');
        $this->makeSymlink(
            $this->tempDir . \DIRECTORY_SEPARATOR . 'packages' . \DIRECTORY_SEPARATOR . 'acme' . \DIRECTORY_SEPARATOR . 'src',
            $this->tempDir . \DIRECTORY_SEPARATOR . 'package-alias',
        );
        \file_put_contents(
            $this->tempDir . \DIRECTORY_SEPARATOR . 'composer.json',
            (string) \json_encode(['autoload' => ['psr-4' => ['Acme\\' => './package-alias']]]),
        );

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('packages/acme/src', (string) \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml'));
        $this->assertStringNotContainsString('packages/ exists but no source root under it is scanned', $tester->getDisplay());
    }

    #[Test]
    public function filesystem_identity_handles_case_variants_when_supported(): void
    {
        $this->makeDir('packages/acme/src');
        if (!\is_dir($this->tempDir . \DIRECTORY_SEPARATOR . 'PACKAGES' . \DIRECTORY_SEPARATOR . 'ACME' . \DIRECTORY_SEPARATOR . 'SRC')) {
            $this->markTestSkipped('The filesystem is case-sensitive, so equivalent case variants cannot be exercised.');
        }

        \file_put_contents(
            $this->tempDir . \DIRECTORY_SEPARATOR . 'composer.json',
            (string) \json_encode(['autoload' => ['psr-4' => ['Acme\\' => [
                'packages/acme/src',
                'PACKAGES/ACME/SRC',
            ]]]]),
        );

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $contents = (string) \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        $this->assertSame(1, \substr_count($contents, '<directory name="packages/acme/src"/>'));
        $this->assertStringNotContainsString('PACKAGES/ACME/SRC', $contents);
        $this->assertStringNotContainsString('packages/ exists but no source root under it is scanned', $tester->getDisplay());
    }

    #[Test]
    public function canonical_paths_deduplicate_non_ascii_case_variants_when_supported(): void
    {
        $this->makeDir('packages/Äcme/src');
        if (!\is_dir($this->tempDir . \DIRECTORY_SEPARATOR . 'packages' . \DIRECTORY_SEPARATOR . 'äCME' . \DIRECTORY_SEPARATOR . 'SRC')) {
            $this->markTestSkipped('The filesystem does not resolve the non-ASCII case variant equivalently.');
        }

        \file_put_contents(
            $this->tempDir . \DIRECTORY_SEPARATOR . 'composer.json',
            (string) \json_encode(['autoload' => ['psr-4' => ['Acme\\' => [
                'packages/Äcme/src',
                'packages/äCME/SRC',
            ]]]], \JSON_UNESCAPED_UNICODE),
        );

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $contents = (string) \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        $this->assertSame(1, \substr_count($contents, '<directory name="packages/Äcme/src"/>'));
        $this->assertStringNotContainsString('packages/äCME/SRC', $contents);
        $this->assertStringNotContainsString('packages/ exists but no source root under it is scanned', $tester->getDisplay());
    }

    #[Test]
    public function scanned_external_tests_symlink_does_not_report_tests_as_skipped(): void
    {
        $outside = $this->makeExternalDir('external-tests');
        $this->makeSymlink($outside, $this->tempDir . \DIRECTORY_SEPARATOR . 'tests');
        \file_put_contents(
            $this->tempDir . \DIRECTORY_SEPARATOR . 'composer.json',
            (string) \json_encode(['require-dev' => ['psalm/plugin-phpunit' => '^0.1']]),
        );

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $contents = (string) \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        $escapedOutside = \htmlspecialchars((string) \realpath($outside), \ENT_QUOTES | \ENT_XML1, 'UTF-8');
        $this->assertStringContainsString('<directory name="' . $escapedOutside . '"/>', $contents);
        $this->assertStringNotContainsString('tests/ dir skipped', $tester->getDisplay());
    }

    #[Test]
    public function preserves_nested_roots_and_conventional_then_composer_order(): void
    {
        \file_put_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'artisan', "#!/usr/bin/env php\n");
        $this->makeDir('app');
        $this->makeDir('config');
        $this->makeDir('database/factories');
        $this->makeDir('z-source');
        $this->makeDir('a-source');
        \file_put_contents(
            $this->tempDir . \DIRECTORY_SEPARATOR . 'composer.json',
            (string) \json_encode(['autoload' => ['psr-4' => [
                'Z\\' => 'z-source',
                'A\\' => 'a-source',
                'Factories\\' => 'database/factories',
            ]]]),
        );

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $contents = (string) \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        $expected = ['app', 'config', 'database', 'z-source', 'a-source', 'database/factories'];
        $lastPosition = -1;
        foreach ($expected as $directory) {
            $position = \strpos($contents, '<directory name="' . $directory . '"/>');
            $this->assertIsInt($position, \sprintf('Expected %s in generated XML.', $directory));
            $this->assertGreaterThan($lastPosition, $position);
            $lastPosition = $position;
        }
    }

    #[Test]
    public function missing_composer_directories_are_skipped(): void
    {
        $this->makeDir('src');
        \file_put_contents(
            $this->tempDir . \DIRECTORY_SEPARATOR . 'composer.json',
            (string) \json_encode(['autoload' => ['psr-4' => [
                'Missing\\' => 'does-not-exist',
                'Present\\' => 'src',
            ]]]),
        );

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $contents = (string) \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        $this->assertStringNotContainsString('does-not-exist', $contents);
        $this->assertStringContainsString('<directory name="src"/>', $contents);
    }

    #[Test]
    public function escapes_xml_special_chars_in_emitted_paths(): void
    {
        // A path with XML-special chars must be escaped, or init reports success while
        // writing an unparseable psalm.xml.
        \file_put_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'artisan', "#!/usr/bin/env php\n");
        \mkdir($this->tempDir . \DIRECTORY_SEPARATOR . 'app');
        \file_put_contents(
            $this->tempDir . \DIRECTORY_SEPARATOR . 'composer.json',
            (string) \json_encode(['autoload' => ['psr-4' => ['Acme\\' => 'packages/Foo & Bar/src']]]),
        );
        $this->makeDir('packages/Foo & Bar/src');

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $contents = (string) \file_get_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        $this->assertStringContainsString('packages/Foo &amp; Bar/src', $contents);
        $previous = \libxml_use_internal_errors(true);
        try {
            $this->assertNotFalse(\simplexml_load_string($contents), 'Generated psalm.xml must be well-formed.');
        } finally {
            \libxml_clear_errors();
            \libxml_use_internal_errors($previous);
        }
    }

    /** Create a nested directory tree under the temp dir (POSIX-style path). */
    private function makeDir(string $relative): void
    {
        $path = $this->tempDir . \DIRECTORY_SEPARATOR . \str_replace('/', \DIRECTORY_SEPARATOR, $relative);
        if (! \is_dir($path) && ! \mkdir($path, 0o777, true) && ! \is_dir($path)) {
            throw new \RuntimeException(\sprintf('Failed to create directory %s', $path));
        }
    }

    private function makeExternalDir(string $suffix): string
    {
        $path = $this->tempDir . '-' . $suffix;
        if (!\mkdir($path) && !\is_dir($path)) {
            throw new \RuntimeException(\sprintf('Failed to create directory %s', $path));
        }

        $this->externalPaths[] = $path;

        return $path;
    }

    private function makeSymlink(string $target, string $link): void
    {
        if (!\function_exists('symlink') || !@\symlink($target, $link)) {
            $this->markTestSkipped('The platform or filesystem does not permit creating symbolic links.');
        }
    }

    private function makeTester(): CommandTester
    {
        $command = new InitCommand($this->tempDir);
        $application = new Application();
        $application->addCommand($command);

        return new CommandTester($application->find('init'));
    }
}
