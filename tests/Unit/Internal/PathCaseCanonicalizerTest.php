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

        // These tests call the canonicalizer directly, bypassing Plugin::resetInvocationState(),
        // so the dirent memo has to be dropped here or one test's listing answers the next.
        PathCaseCanonicalizer::reset();
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

    /**
     * A trailing separator on a FILE is a spelling no filesystem opens (a file is not a directory),
     * so it is not a casing problem to fix: rewriting it onto the real file path would hand the
     * caller a path it could not have reached itself.
     */
    #[Test]
    public function a_trailing_separator_on_a_file_passes_through_unchanged(): void
    {
        \file_put_contents($this->root . '/COMPOSER.JSON', "{}\n");

        $this->assertSame(
            $this->root . '/COMPOSER.JSON/',
            PathCaseCanonicalizer::canonicalize($this->root . '/COMPOSER.JSON/'),
        );
    }

    /**
     * The case-insensitive fallback must never invent resolution. Where only `Resources` exists as
     * a dirent, `resources` is a path the application itself cannot open, and answering it with the
     * sibling would claim a view root Laravel would fail to read.
     */
    #[Test]
    public function a_spelling_the_filesystem_refuses_is_not_rewritten_onto_a_sibling(): void
    {
        if ($this->filesystemIsCaseInsensitive()) {
            $this->markTestSkipped('needs a case-sensitive filesystem: elsewhere both spellings open the same directory');
        }

        \mkdir($this->root . '/Resources/views', 0o777, true);

        $this->assertSame(
            $this->root . '/resources/views',
            PathCaseCanonicalizer::canonicalize($this->root . '/resources/views'),
        );
    }

    /**
     * The ownership check canonicalizes once per surviving template, and every call re-walks the
     * whole path, so without a memo one view root's dirents are re-read once per template in it.
     * Proven behaviourally: a rename that changes only a dirent's CASE is invisible to a cached
     * listing, and visible again after {@see PathCaseCanonicalizer::reset()}.
     */
    #[Test]
    public function repeat_lookups_are_served_from_the_per_invocation_dirent_memo(): void
    {
        if (!$this->filesystemIsCaseInsensitive()) {
            $this->markTestSkipped('needs a case-insensitive filesystem: a case-only rename changes identity elsewhere');
        }

        \mkdir($this->root . '/Resources/views', 0o777, true);
        $request = $this->root . '/resources/views';

        $this->assertSame($this->root . '/Resources/views', PathCaseCanonicalizer::canonicalize($request));

        \rename($this->root . '/Resources', $this->root . '/RESOURCES');

        $this->assertSame(
            $this->root . '/Resources/views',
            PathCaseCanonicalizer::canonicalize($request),
            'the second lookup must come from the memo, not a fresh scandir',
        );

        PathCaseCanonicalizer::reset();

        $this->assertSame(
            $this->root . '/RESOURCES/views',
            PathCaseCanonicalizer::canonicalize($request),
            'reset() must drop the memo so a later invocation sees the real dirents',
        );
    }

    /**
     * A Windows-style path split on `/` has no leading separator, so walking it would start at
     * `scandir()` of the process cwd (or a drive's own cwd) and could rewrite segments against
     * unrelated dirents. Same for a relative path. Both must pass through untouched.
     */
    #[Test]
    public function windows_style_and_relative_paths_pass_through_unchanged(): void
    {
        $this->assertSame(
            'C:\\project\\Resources\\views',
            PathCaseCanonicalizer::canonicalize('C:\\project\\Resources\\views'),
        );
        $this->assertSame(
            '\\\\server\\share\\Resources',
            PathCaseCanonicalizer::canonicalize('\\\\server\\share\\Resources'),
        );
        $this->assertSame('relative/views', PathCaseCanonicalizer::canonicalize('relative/views'));
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
