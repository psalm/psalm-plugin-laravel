<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Internal\PathCaseCanonicalizer;

/**
 * `realpath()` preserves the caller's casing, so it cannot be the fix for #1552 — these tests
 * exercise the canonicalizer directly instead. Case (in)sensitivity is a filesystem CAPABILITY, not
 * an OS fact, so every case-folding assertion is gated on a probe that creates a directory and
 * checks whether its differently-cased spelling also opens it, per house rule.
 */
#[CoversClass(PathCaseCanonicalizer::class)]
final class PathCaseCanonicalizerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $root = \realpath(\sys_get_temp_dir()) . '/psalm-case-canon-' . \bin2hex(\random_bytes(8));
        \mkdir($root, 0o777, true);
        $this->root = $root;
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    private function removeTree(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }

        foreach (\array_diff(\scandir($path) ?: [], ['.', '..']) as $entry) {
            $child = $path . '/' . $entry;
            \is_dir($child) && !\is_link($child) ? $this->removeTree($child) : \unlink($child);
        }

        \rmdir($path);
    }

    /** True when a directory's upper/lower-cased spelling also resolves to it, on THIS filesystem. */
    private function filesystemIsCaseInsensitive(): bool
    {
        $lower = $this->root . '/probe-dir';
        \mkdir($lower, 0o777, true);

        return \is_dir($this->root . '/PROBE-DIR');
    }

    #[Test]
    public function a_differently_cased_request_resolves_to_the_real_dirent_casing(): void
    {
        if (!$this->filesystemIsCaseInsensitive()) {
            $this->markTestSkipped('needs a case-insensitive filesystem: only one spelling can exist there');
        }

        // Only one dirent can exist on this filesystem no matter how it is asked for; created as
        // `Resources`, `scandir()` reports that exact casing, so asking with `resources` exercises
        // the "no exact match, unique case-insensitive candidate" fallback branch.
        \mkdir($this->root . '/Resources/views', 0o777, true);

        $this->assertSame(
            $this->root . '/Resources/views',
            PathCaseCanonicalizer::canonicalize($this->root . '/resources/views'),
        );
    }

    #[Test]
    public function distinct_dirents_on_a_case_sensitive_filesystem_stay_distinct(): void
    {
        if ($this->filesystemIsCaseInsensitive()) {
            $this->markTestSkipped('needs a case-sensitive filesystem to hold two colliding dirents');
        }

        \mkdir($this->root . '/Resources/views', 0o777, true);
        \mkdir($this->root . '/resources/views', 0o777, true);

        $this->assertSame(
            $this->root . '/Resources/views',
            PathCaseCanonicalizer::canonicalize($this->root . '/Resources/views'),
        );
        $this->assertSame(
            $this->root . '/resources/views',
            PathCaseCanonicalizer::canonicalize($this->root . '/resources/views'),
        );
    }

    #[Test]
    public function a_nonexistent_path_is_returned_unchanged(): void
    {
        $missing = $this->root . '/never/created/at/all';

        $this->assertSame($missing, PathCaseCanonicalizer::canonicalize($missing));
    }

    #[Test]
    public function a_path_through_a_symlinked_root_keeps_the_symlink_segment_untouched(): void
    {
        $target = $this->root . '/actual';
        \mkdir($target . '/views', 0o777, true);
        \symlink($target, $this->root . '/linked');

        // The canonicalizer only matches dirents at each level as given — it never dereferences a
        // symlink itself, that is realpath()'s job, applied separately by callers.
        $this->assertSame(
            $this->root . '/linked/views',
            PathCaseCanonicalizer::canonicalize($this->root . '/linked/views'),
        );
    }
}
