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
        $message = (string) \preg_replace('/\s+in\s+\S+\.php:\d+/u', '', $message);

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
     * The path prefix is anchored to start-of-line or whitespace (via lookbehind),
     * and the middle segment is non-greedy so that vendor paths containing an
     * inner "src/" directory (e.g. "vendor/laravel/framework/src/...") are not
     * collapsed into "vendorsrc/...".
     *
     * @psalm-pure
     */
    private static function sanitizeTrace(string $trace): string
    {
        return (string) \preg_replace(
            '#(?<=\s|^)[A-Za-z]?:?[\\/](?:[^\s:()]*?[\\/])?(vendor[\\/]|src[\\/])#u',
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
