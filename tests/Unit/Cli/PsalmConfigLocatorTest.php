<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Cli\PsalmConfigLocator;

#[CoversClass(PsalmConfigLocator::class)]
final class PsalmConfigLocatorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'psalm-laravel-config-locator-' . \uniqid('', true);
        if (! \mkdir($this->tempDir) && ! \is_dir($this->tempDir)) {
            throw new \RuntimeException(\sprintf('Failed to create temp directory %s', $this->tempDir));
        }
    }

    protected function tearDown(): void
    {
        $entries = @\scandir($this->tempDir) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $this->tempDir . \DIRECTORY_SEPARATOR . $entry;
            \is_dir($path) ? @\rmdir($path) : @\unlink($path);
        }

        @\rmdir($this->tempDir);
    }

    #[Test]
    public function returns_null_when_neither_file_exists(): void
    {
        $this->assertNull(PsalmConfigLocator::locate($this->tempDir));
    }

    #[Test]
    public function finds_psalm_xml_when_it_is_the_only_one_present(): void
    {
        \file_put_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml', '<psalm/>');

        $this->assertSame(
            $this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml',
            PsalmConfigLocator::locate($this->tempDir),
        );
    }

    #[Test]
    public function finds_psalm_xml_dist_when_it_is_the_only_one_present(): void
    {
        \file_put_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml.dist', '<psalm/>');

        $this->assertSame(
            $this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml.dist',
            PsalmConfigLocator::locate($this->tempDir),
        );
    }

    #[Test]
    public function prefers_psalm_xml_over_psalm_xml_dist_when_both_present(): void
    {
        \file_put_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml', '<psalm/>');
        \file_put_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml.dist', '<psalm/>');

        $this->assertSame(
            $this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml',
            PsalmConfigLocator::locate($this->tempDir),
        );
    }

    #[Test]
    public function skips_a_directory_named_psalm_xml(): void
    {
        // Pins the bug this class was extracted to fix: one of the two prior,
        // independent copies used file_exists() (true for a directory too),
        // the other is_file(). A directory named psalm.xml must not shadow a
        // real psalm.xml.dist.
        \mkdir($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml');
        \file_put_contents($this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml.dist', '<psalm/>');

        $this->assertSame(
            $this->tempDir . \DIRECTORY_SEPARATOR . 'psalm.xml.dist',
            PsalmConfigLocator::locate($this->tempDir),
        );
    }
}
