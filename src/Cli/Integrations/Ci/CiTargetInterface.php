<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli\Integrations\Ci;

/**
 * Adapter contract for a single CI provider (GitHub Actions, GitLab CI, ...).
 *
 * Each implementation knows how to render the Psalm workflow for its provider
 * and where it belongs inside a project. The adapter itself does not mutate
 * the project: it reads the template and computes a target path, but never
 * writes. The caller (AddCommand) owns the actual write so all adapters
 * share one error-handling and atomic-write path.
 *
 * Adding a new CI provider is a two-step change: implement this interface,
 * then register the instance in CiTargetRegistry::default().
 *
 * @psalm-mutable Interface does not require implementations to be immutable.
 */
interface CiTargetInterface
{
    /**
     * Short, stable identifier used on the CLI (e.g. `github`, `gitlab`).
     *
     * Must be lowercase and must not collide with the reserved alias `ci`
     * (which CiTargetRegistry maps to an auto-detected provider).
     *
     * @psalm-mutation-free
     */
    public function id(): string;

    /**
     * Human-readable name rendered in CLI output (e.g. `GitHub Actions`).
     *
     * @psalm-mutation-free
     */
    public function displayName(): string;

    /**
     * Heuristic for whether the project is using this CI provider.
     *
     * Used by the `ci` alias to pick the right adapter automatically.
     * Implementations should confine reads to files under $projectRoot and
     * must not execute anything. Marked impure because filesystem reads
     * (`is_dir` and friends) are impure by Psalm's definition.
     *
     * @psalm-impure
     */
    public function detect(string $projectRoot): bool;

    /**
     * Compute the file to write without touching disk.
     *
     * Throws \RuntimeException if the bundled template cannot be read, which
     * indicates a broken plugin install (a file shipped with the package has
     * gone missing); surface this loudly so the user can reinstall instead of
     * debugging silent CI failures. Marked impure because it reads the
     * bundled template via `file_get_contents`.
     *
     * @psalm-impure
     */
    public function plan(string $projectRoot): CiPlan;
}
