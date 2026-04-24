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
     * Absolute destination path for the workflow file, e.g.
     * `/abs/project/.github/workflows/psalm.yml`.
     *
     * @var non-empty-string
     */
    public string $path;

    /**
     * Full file contents, copied from the adapter's bundled template.
     *
     * @var non-empty-string
     */
    public string $contents;

    /**
     * True when $path already exists on disk. AddCommand uses this to decide
     * between "create" (silent) and "overwrite" (needs --force or interactive
     * confirmation).
     */
    public bool $targetExists;

    /**
     * @param non-empty-string $path
     *
     * Validates the invariants in the constructor so every CiPlan instance is
     * guaranteed well-formed regardless of which adapter produced it. An empty
     * `$contents` would be written verbatim by AddCommand, producing a zero-byte
     * workflow file that breaks CI with a confusing "invalid workflow" error.
     */
    public function __construct(string $path, string $contents, bool $targetExists)
    {
        if ($contents === '') {
            throw new \InvalidArgumentException('CiPlan::$contents must not be empty; a zero-byte workflow would break CI.');
        }

        $this->path = $path;
        $this->contents = $contents;
        $this->targetExists = $targetExists;
    }
}
