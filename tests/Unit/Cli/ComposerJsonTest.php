<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Cli\ComposerJson;

#[CoversClass(ComposerJson::class)]
final class ComposerJsonTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'psalm-laravel-composer-json-' . \uniqid('', true);
        if (! \mkdir($this->tempDir) && ! \is_dir($this->tempDir)) {
            throw new \RuntimeException(\sprintf('Failed to create temp directory %s', $this->tempDir));
        }
    }

    protected function tearDown(): void
    {
        $entries = @\scandir($this->tempDir) ?: [];
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @\unlink($this->tempDir . \DIRECTORY_SEPARATOR . $entry);
            }
        }

        @\rmdir($this->tempDir);
    }

    private function writeComposerJson(string $contents): void
    {
        \file_put_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'composer.json', $contents);
    }

    #[Test]
    public function read_returns_null_when_composer_json_is_absent(): void
    {
        $this->assertNull(ComposerJson::read($this->tempDir));
    }

    #[Test]
    public function read_throws_on_invalid_json(): void
    {
        $this->writeComposerJson('{not valid json');

        $this->expectException(\JsonException::class);
        ComposerJson::read($this->tempDir);
    }

    #[Test]
    public function read_throws_when_json_does_not_decode_to_an_object(): void
    {
        $this->writeComposerJson('"just a string"');

        $this->expectException(\RuntimeException::class);
        ComposerJson::read($this->tempDir);
    }

    #[Test]
    public function require_php_returns_the_constraint_when_present(): void
    {
        $this->writeComposerJson(\json_encode(['require' => ['php' => '^8.2']], \JSON_THROW_ON_ERROR));

        $composerJson = ComposerJson::read($this->tempDir);

        $this->assertNotNull($composerJson);
        $this->assertSame('^8.2', $composerJson->requirePhp());
    }

    #[Test]
    public function require_php_is_null_when_unset(): void
    {
        $this->writeComposerJson('{}');

        $composerJson = ComposerJson::read($this->tempDir);

        $this->assertNotNull($composerJson);
        $this->assertNull($composerJson->requirePhp());
    }

    #[Test]
    public function has_package_matches_require_and_require_dev(): void
    {
        $this->writeComposerJson(\json_encode([
            'require' => ['psalm/plugin-laravel' => '^4.0'],
            'require-dev' => ['psalm/plugin-phpunit' => '^1.0'],
        ], \JSON_THROW_ON_ERROR));

        $composerJson = ComposerJson::read($this->tempDir);

        $this->assertNotNull($composerJson);
        $this->assertTrue($composerJson->hasPackage('psalm/plugin-laravel'));
        $this->assertTrue($composerJson->hasPackage('psalm/plugin-phpunit'));
        $this->assertFalse($composerJson->hasPackage('vimeo/psalm'));
    }

    #[Test]
    public function vendor_dir_defaults_to_vendor_when_unconfigured(): void
    {
        $this->writeComposerJson('{}');

        $composerJson = ComposerJson::read($this->tempDir);

        $this->assertNotNull($composerJson);
        $this->assertSame('vendor', $composerJson->vendorDir());
    }

    #[Test]
    public function vendor_dir_reflects_a_relocated_configuration(): void
    {
        $this->writeComposerJson(\json_encode(['config' => ['vendor-dir' => 'lib/vendor']], \JSON_THROW_ON_ERROR));

        $composerJson = ComposerJson::read($this->tempDir);

        $this->assertNotNull($composerJson);
        $this->assertSame('lib/vendor', $composerJson->vendorDir());
    }

    #[Test]
    public function vendor_dir_strips_leading_dot_slash_and_trailing_slash(): void
    {
        $this->writeComposerJson(\json_encode(['config' => ['vendor-dir' => './custom-vendor/']], \JSON_THROW_ON_ERROR));

        $composerJson = ComposerJson::read($this->tempDir);

        $this->assertNotNull($composerJson);
        $this->assertSame('custom-vendor', $composerJson->vendorDir());
    }

    #[Test]
    public function autoload_psr4_dirs_accepts_string_and_array_forms_and_dedupes(): void
    {
        $this->writeComposerJson(\json_encode([
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                    'App\\Tests\\' => ['tests/', 'src/'],
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $composerJson = ComposerJson::read($this->tempDir);

        $this->assertNotNull($composerJson);
        $this->assertSame(['src', 'tests'], $composerJson->autoloadPsr4Dirs());
    }

    #[Test]
    public function autoload_psr4_dirs_is_empty_when_unset(): void
    {
        $this->writeComposerJson('{}');

        $composerJson = ComposerJson::read($this->tempDir);

        $this->assertNotNull($composerJson);
        $this->assertSame([], $composerJson->autoloadPsr4Dirs());
    }
}
