<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli\Integrations\Ci;

/**
 * Describes the single file a CI adapter intends to write.
 *
 * Plans are computed first and applied second so `--dry-run` can report the
 * exact change without mutating the project, and so existing-file detection
 * happens before any prompting.
 *
 * @psalm-immutable
 */
final readonly class CiPlan
{
    /**
     * @param non-empty-string $path
     */
    public function __construct(
        /**
         * Absolute destination path for the workflow file, e.g.
         * `/abs/project/.github/workflows/psalm.yml`.
         */
        public string $path,
        /**
         * Full file contents, copied from the adapter's bundled template.
         */
        public string $contents,
        /**
         * True when $path already exists on disk. AddCommand uses this to
         * decide between "create" (silent) and "overwrite" (needs --force or
         * interactive confirmation).
         */
        public bool $targetExists,
    ) {}
}
