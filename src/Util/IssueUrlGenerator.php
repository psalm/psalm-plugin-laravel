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
            \urlencode("Error on generating stub files: {$throwable->getMessage()}"),
            \urlencode(self::buildBody($throwable)),
        );
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
     * @psalm-pure
     */
    private static function sanitizeTrace(string $trace): string
    {
        // Replace absolute paths up to and including /vendor/ or a known package dir
        // e.g. "/home/user/project/vendor/psalm/..." → "vendor/psalm/..."
        $trace = (string) \preg_replace('#[A-Za-z]?:?[\\/](?:[^\s:()]*[\\/])?(vendor[\\/])#u', '$1', $trace);

        // Replace any remaining absolute paths to src/ within this package
        return (string) \preg_replace('#[A-Za-z]?:?[\\/](?:[^\s:()]*[\\/])?(src[\\/])#u', '$1', $trace);
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
