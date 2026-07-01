<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli;

/**
 * Locates a project's Psalm config file.
 *
 * Shared by InitCommand and Diagnostics, which both need "does psalm.xml (or
 * psalm.xml.dist) already exist, and which one" — extracted here after the
 * two independent copies had already drifted on the existence check itself
 * (`file_exists`, which is also true for a directory, vs. `is_file`) (#1195).
 */
final class PsalmConfigLocator
{
    /**
     * Config file names Psalm itself recognises, in the same precedence order
     * Psalm uses when locating a project's config. `psalm.xml` wins over
     * `psalm.xml.dist` when both are present.
     */
    private const FILENAMES = ['psalm.xml', 'psalm.xml.dist'];

    /**
     * First existing Psalm config path under $projectRoot, following Psalm's
     * own precedence. Null when neither exists.
     */
    public static function locate(string $projectRoot): ?string
    {
        foreach (self::FILENAMES as $name) {
            $candidate = $projectRoot . \DIRECTORY_SEPARATOR . $name;
            if (\is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
