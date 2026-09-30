<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Internal\AtomicFileWriter;

#[CoversClass(AtomicFileWriter::class)]
final class AtomicFileWriterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/psalm-laravel-atomic-' . \uniqid('', true);
        \mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    #[Test]
    public function it_replaces_the_target_and_leaves_no_temp_file(): void
    {
        $target = $this->dir . '/aliases.phpstub';
        \file_put_contents($target, 'old');

        $this->assertNull(AtomicFileWriter::write($target, 'new'));

        $this->assertSame('new', \file_get_contents($target));
        $this->assertSame(['aliases.phpstub'], $this->listDir());
    }

    #[Test]
    public function it_reports_a_reason_and_cleans_up_when_the_rename_fails(): void
    {
        // A non-empty directory cannot be replaced by a file, so rename() fails after the temp file is written.
        $target = $this->dir . '/target';
        \mkdir($target);
        \file_put_contents($target . '/keep', 'untouched');

        $reason = AtomicFileWriter::write($target, 'new');

        $this->assertNotNull($reason);
        $this->assertStringContainsString($target, $reason);
        $this->assertSame(['target'], $this->listDir());
        $this->assertSame('untouched', \file_get_contents($target . '/keep'));
    }

    /** @return list<string> */
    private function listDir(): array
    {
        return \array_values(\array_diff(\scandir($this->dir) ?: [], ['.', '..']));
    }

    private function removeTree(string $path): void
    {
        if (\is_dir($path) && !\is_link($path)) {
            foreach (\array_diff(\scandir($path) ?: [], ['.', '..']) as $entry) {
                $this->removeTree($path . '/' . $entry);
            }

            \rmdir($path);
        } elseif (\file_exists($path)) {
            \unlink($path);
        }
    }
}
