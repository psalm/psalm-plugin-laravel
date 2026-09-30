<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Concerns;

/**
 * Private per-process fixture copies for subprocess tests. A copy gets its own working directory,
 * so Psalm's cache and the plugin's `sys_get_temp_dir()/psalm-laravel-<md5(cwd)>` stub cache never
 * collide with another test (or paratest worker) analysing the original fixture.
 */
trait CopiesFixtureDirectories
{
    /**
     * The destination usually lives inside the source (see the fixture-ownership rules in
     * tests/README.md), so the listing is snapshotted before it exists; iterating lazily would
     * copy the copy into itself until the path overflows.
     */
    private function copyDirectory(string $source, string $destination): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var array<string, bool> $entries relative path => is directory */
        $entries = [];
        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            $entries[$iterator->getSubPathName()] = $item->isDir();
        }

        \mkdir($destination, 0777, true);

        foreach ($entries as $relativePath => $isDir) {
            $target = $destination . '/' . $relativePath;
            if ($isDir) {
                \mkdir($target, 0777, true);
            } else {
                \copy($source . '/' . $relativePath, $target);
            }
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!\is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                \rmdir($item->getPathname());
            } else {
                \unlink($item->getPathname());
            }
        }

        \rmdir($directory);
    }
}
