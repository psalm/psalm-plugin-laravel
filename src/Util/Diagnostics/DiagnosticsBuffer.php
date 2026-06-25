<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Util\Diagnostics;

/**
 * In-memory log buffer for plugin-initialization diagnostics.
 *
 * Plugin init can surface degraded-state notices: a partial Laravel boot, an
 * unavailable `migrator`, an unreadable stub directory. Emitting them inline
 * scatters them through Psalm's progress output; collecting them here lets the
 * plugin flush one grouped block at a stable point (after init succeeds) or fold
 * them into {@see \Psalm\LaravelPlugin\Util\InternalErrorReporter} on failure.
 *
 * Deliberately a plain append-and-render log, not an event system.
 *
 * `@psalm-api`: production only feeds `warning()` (via the buffered progress capture),
 * so info()/error()/entries() have callers only in the unit tests, which live outside
 * the analysed `src` tree. Without it self-analysis flags them as unused (same reason
 * {@see \Psalm\LaravelPlugin\Plugin} carries it). They round out the severity log the
 * buffer models; a future producer can emit info/error without re-plumbing.
 *
 * @psalm-import-type DiagnosticStage from Diagnostic
 *
 * @psalm-api
 * @psalm-external-mutation-free
 *
 * @internal
 */
final class DiagnosticsBuffer
{
    /** @var list<Diagnostic> */
    private array $diagnostics = [];

    /** @param DiagnosticStage $stage */
    public function info(string $stage, string $message): void
    {
        $this->diagnostics[] = new Diagnostic('info', $stage, $message);
    }

    /** @param DiagnosticStage $stage */
    public function warning(string $stage, string $message): void
    {
        $this->diagnostics[] = new Diagnostic('warning', $stage, $message);
    }

    /** @param DiagnosticStage $stage */
    public function error(string $stage, string $message): void
    {
        $this->diagnostics[] = new Diagnostic('error', $stage, $message);
    }

    /** @psalm-mutation-free */
    public function isEmpty(): bool
    {
        return $this->diagnostics === [];
    }

    /**
     * @return list<Diagnostic>
     *
     * @psalm-mutation-free
     */
    public function entries(): array
    {
        return $this->diagnostics;
    }

    /** @psalm-external-mutation-free */
    public function clear(): void
    {
        $this->diagnostics = [];
    }

    /**
     * Render all buffered diagnostics as one grouped block: most severe first,
     * stable (insertion = lifecycle order) within a severity. Each line is tagged
     * `[severity/stage]` so the grouping reads at a glance. Returns '' when empty
     * so callers can skip writing anything.
     *
     * @psalm-mutation-free
     */
    public function format(): string
    {
        if ($this->isEmpty()) {
            return '';
        }

        // usort is stable in PHP 8+, so ordering by severity alone keeps each
        // severity's entries in the order the lifecycle produced them.
        $ordered = $this->diagnostics;
        \usort(
            $ordered,
            static fn(Diagnostic $a, Diagnostic $b): int
                => self::severityRank($a->severity) <=> self::severityRank($b->severity),
        );

        $lines = \array_map(
            static fn(Diagnostic $diagnostic): string
                => \sprintf('  [%s/%s] %s', $diagnostic->severity, $diagnostic->stage, $diagnostic->message),
            $ordered,
        );

        $count = \count($ordered);
        $header = \sprintf('Laravel plugin: %d initialization %s:', $count, $count === 1 ? 'notice' : 'notices');

        return $header . "\n" . \implode("\n", $lines);
    }

    /** @psalm-pure */
    private static function severityRank(string $severity): int
    {
        return match ($severity) {
            'error' => 0,
            'warning' => 1,
            default => 2, // 'info'
        };
    }
}
