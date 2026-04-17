<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Util;

use Composer\InstalledVersions;

/** @internal */
final class IssueUrlGenerator
{
    public static function generate(\Throwable $throwable): string
    {
        return \sprintf(
            'https://github.com/psalm/psalm-plugin-laravel/issues/new?template=bug_report.md&title=%s&body=%s',
            \urlencode('Plugin initialization error: ' . self::sanitizeTitle($throwable->getMessage())),
            \urlencode(self::buildBody($throwable)),
        );
    }

    /**
     * Strip noisy, environment-specific fragments from the raw throwable message so the
     * GitHub issue title stays short and free of private filesystem paths.
     *
     * @psalm-pure
     */
    private static function sanitizeTitle(string $message): string
    {
        // Drop the "for command with CLI args \"...\"" suffix Psalm appends to wrapped PHP errors.
        $message = (string) \preg_replace('/\s+for command with CLI args\s+".*$/s', '', $message);

        // Drop " in /absolute/path/to/file.php:123" fragments that leak the reporter's filesystem.
        // Non-greedy match (`.+?`) instead of `\S+` so Windows paths containing spaces
        // (e.g. "C:\Users\John Doe\file.php:12") are also handled.
        $message = (string) \preg_replace('/\s+in\s+.+?\.php:\d+/u', '', $message);

        // Strip a leading "PHP (Fatal error|Error|Warning|Notice): " prefix that duplicates context.
        $message = (string) \preg_replace('/^PHP\s+(?:Fatal\s+error|Error|Warning|Notice):\s+/i', '', $message);

        return \trim($message);
    }

    private static function buildBody(\Throwable $throwable): string
    {
        $versions = self::collectVersions();
        $trace = self::sanitizeTrace($throwable->__toString());

        $body = '';

        if ($versions !== []) {
            $body .= "**Versions:**\n";
            foreach ($versions as $package => $version) {
                $body .= "- {$package}: {$version}\n";
            }

            $body .= "\n";
        }

        $body .= "```\n{$trace}\n```";

        return $body;
    }

    /**
     * Remove absolute paths from the trace to avoid leaking private filesystem info.
     * Keeps only the path relative to the project/vendor root.
     *
     * e.g. "/home/user/project/vendor/psalm/..." → "vendor/psalm/..."
     *      "/home/user/project/src/Plugin.php"   → "src/Plugin.php"
     *
     * Runs two passes — vendor first, then src — instead of one alternation. On a
     * checkout like "/Users/alice/src/project/vendor/laravel/framework/src/..." the
     * single-regex alternation `(vendor/|src/)` would stop at the *first* `src/` in
     * the absolute prefix and leak "project/vendor/...". Splitting into two passes
     * lets the vendor pass consume the whole absolute prefix up to `vendor/`, and
     * the subsequent src pass only fires when no `vendor/` segment exists.
     *
     * The middle segment is **greedy**, so each pass prefers the LAST `vendor/` or
     * `src/` segment in an absolute path. That matters for paths like
     * "/Users/alice/src/project/src/Plugin.php" (no vendor, `src/` twice): a
     * non-greedy middle would match the first `src/` and leak "project/src/…",
     * whereas the greedy middle collapses the whole prefix down to `src/Plugin.php`.
     * Vendor paths containing an inner `src/` (e.g. "vendor/laravel/framework/src/…")
     * are still safe because the src pass's lookbehind requires a boundary before
     * the leading path separator — and once vendor has been collapsed, nothing in
     * the relative "vendor/.../src/..." path is preceded by one.
     *
     * Each path prefix is anchored to a safe boundary (start-of-line, whitespace, or
     * one of the quote/paren characters that PHP stack traces use around stringified
     * arguments, e.g. `#0 /path/File.php(79): Foo->bar('/dev/some/path', 79)`).
     *
     * @psalm-pure
     */
    private static function sanitizeTrace(string $trace): string
    {
        $boundary = '(?<=\s|^|\'|"|\()';

        // Path-separator character class. `[\\\\/]` in this single-quoted PHP string
        // becomes the two-char PCRE pattern `[\\/]`, which matches one backslash OR
        // one forward slash. Writing `[\\/]` in the source would instead produce the
        // PCRE pattern `[\/]` — that only matches `/` and silently skips Windows
        // backslash paths, so the extra escaping is load-bearing.
        $sep = '[\\\\/]';

        // Vendor pass: collapses any absolute prefix up to "vendor/".
        $trace = (string) \preg_replace(
            '#' . $boundary . '[A-Za-z]?:?' . $sep . '(?:[^\s:()]*' . $sep . ')?(vendor' . $sep . ')#u',
            '$1',
            $trace,
        );

        // Src pass: collapses any absolute prefix up to "src/".
        // Won't re-match an already-relativised "vendor/.../src/..." because the
        // lookbehind requires a safe boundary before the leading path separator.
        return (string) \preg_replace(
            '#' . $boundary . '[A-Za-z]?:?' . $sep . '(?:[^\s:()]*' . $sep . ')?(src' . $sep . ')#u',
            '$1',
            $trace,
        );
    }

    /** @return array<string, string> */
    private static function collectVersions(): array
    {
        $versions = [];

        $packages = [
            'psalm/plugin-laravel' => 'psalm/plugin-laravel',
            'vimeo/psalm' => 'vimeo/psalm',
            'laravel/framework' => 'laravel/framework',
            'orchestra/testbench-core' => 'orchestra/testbench-core',
        ];

        foreach ($packages as $label => $package) {
            try {
                $version = InstalledVersions::getPrettyVersion($package);
                if ($version !== null) {
                    $versions[$label] = $version;
                }
            } catch (\OutOfBoundsException) {
                // Package not installed
            }
        }

        return $versions;
    }
}
