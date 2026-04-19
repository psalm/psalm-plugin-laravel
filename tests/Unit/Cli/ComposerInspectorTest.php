<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Cli\ComposerInspector;

#[CoversClass(ComposerInspector::class)]
final class ComposerInspectorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'psalm-laravel-inspector-' . \uniqid('', true);
        if (! \mkdir($this->tempDir, 0777, true) && ! \is_dir($this->tempDir)) {
            throw new \RuntimeException(\sprintf('Failed to create temp directory %s', $this->tempDir));
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function reports_no_dependencies_when_composer_json_is_missing(): void
    {
        $inspector = new ComposerInspector($this->tempDir);

        $this->assertFalse($inspector->hasDependency('phpunit/phpunit'));
        // Missing composer.json is a legitimate state (fresh project): no warning.
        $this->assertNull($inspector->parseWarning);
    }

    #[Test]
    public function reads_require_and_require_dev(): void
    {
        $this->writeComposerJson([
            'require' => ['illuminate/support' => '^12.0'],
            'require-dev' => ['phpunit/phpunit' => '^11.0'],
        ]);

        $inspector = new ComposerInspector($this->tempDir);

        $this->assertTrue($inspector->hasDependency('illuminate/support'));
        $this->assertTrue($inspector->hasDependency('phpunit/phpunit'));
        $this->assertFalse($inspector->hasDependency('mockery/mockery'));
        $this->assertNull($inspector->parseWarning);
    }

    #[Test]
    public function surfaces_parse_warning_for_malformed_json(): void
    {
        \file_put_contents($this->tempDir . '/composer.json', '{ not valid json');

        $inspector = new ComposerInspector($this->tempDir);

        $this->assertFalse($inspector->hasDependency('phpunit/phpunit'));
        $this->assertNotNull($inspector->parseWarning);
        $this->assertStringContainsString('not valid JSON', $inspector->parseWarning);
    }

    #[Test]
    public function surfaces_parse_warning_for_non_object_json(): void
    {
        \file_put_contents($this->tempDir . '/composer.json', '"just a string"');

        $inspector = new ComposerInspector($this->tempDir);

        $this->assertFalse($inspector->hasDependency('phpunit/phpunit'));
        $this->assertNotNull($inspector->parseWarning);
        $this->assertStringContainsString('must be a JSON object', $inspector->parseWarning);
    }

    #[Test]
    public function tolerates_non_array_require_sections(): void
    {
        // Not treated as a parse error: malformed sub-sections degrade to
        // "no packages in that section" since that's what composer would do.
        $this->writeComposerJson([
            'require' => 'should-be-object',
            'require-dev' => ['phpunit/phpunit' => '^11.0'],
        ]);

        $inspector = new ComposerInspector($this->tempDir);

        $this->assertTrue($inspector->hasDependency('phpunit/phpunit'));
        $this->assertNull($inspector->parseWarning);
    }

    #[Test]
    public function detects_installed_package_via_vendor_composer_json(): void
    {
        $this->writeVendorPackage('psalm/phpunit-plugin');

        $inspector = new ComposerInspector($this->tempDir);

        $this->assertTrue($inspector->hasInstalledPackage('psalm/phpunit-plugin'));
        $this->assertFalse($inspector->hasInstalledPackage('psalm/mockery-plugin'));
    }

    #[Test]
    public function installed_check_requires_composer_json_not_just_directory(): void
    {
        // A bare directory without composer.json does not count as installed
        // (matches Composer's own view — installed packages always carry a
        // composer.json).
        \mkdir($this->tempDir . '/vendor/psalm/phpunit-plugin', 0777, true);

        $inspector = new ComposerInspector($this->tempDir);

        $this->assertFalse($inspector->hasInstalledPackage('psalm/phpunit-plugin'));
    }

    #[Test]
    public function installed_check_reflects_live_vendor_state(): void
    {
        // Unlike dependency metadata (read once at construction), vendor/
        // probes always hit disk: callers may call this after running
        // `composer require` and need the fresh answer.
        $inspector = new ComposerInspector($this->tempDir);
        $this->assertFalse($inspector->hasInstalledPackage('psalm/phpunit-plugin'));

        $this->writeVendorPackage('psalm/phpunit-plugin');

        $this->assertTrue($inspector->hasInstalledPackage('psalm/phpunit-plugin'));
    }

    #[Test]
    public function normalizes_trailing_separator_in_cwd(): void
    {
        $this->writeComposerJson(['require' => ['phpunit/phpunit' => '^11.0']]);

        $inspector = new ComposerInspector($this->tempDir . \DIRECTORY_SEPARATOR);

        $this->assertTrue($inspector->hasDependency('phpunit/phpunit'));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeComposerJson(array $data): void
    {
        $json = \json_encode($data, \JSON_THROW_ON_ERROR);
        \file_put_contents($this->tempDir . '/composer.json', $json);
    }

    private function writeVendorPackage(string $package): void
    {
        $dir = $this->tempDir . '/vendor/' . $package;
        \mkdir($dir, 0777, true);
        \file_put_contents($dir . '/composer.json', \json_encode(['name' => $package], \JSON_THROW_ON_ERROR));
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
