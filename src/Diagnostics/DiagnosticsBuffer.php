<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Diagnostics;

use Psalm\Progress\Progress;

/**
 * In-memory log buffer for plugin-initialisation diagnostics.
 *
 * Warnings raised while the plugin boots Laravel, builds schema, and registers
 * handlers/stubs are collected here instead of being printed immediately, so they
 * never interleave with Psalm's progress output. {@see flushTo()} replays them at a
 * stable point: after a successful init, or as the lead-in for
 * {@see \Psalm\LaravelPlugin\Util\InternalErrorReporter} on a failed one.
 *
 * Deliberately a flat append-and-drain buffer, not an event system.
 *
 * @psalm-import-type DiagnosticSeverity from Diagnostic
 * @psalm-import-type DiagnosticStage from Diagnostic
 *
 * @internal
 */
final class DiagnosticsBuffer
{
    /**
     * Flush order, most severe first. Psalm's {@see Progress} exposes only a warning
     * channel, so severity drives the replay ordering rather than a per-level stream.
     */
    private const SEVERITY_ORDER = ['error', 'warning', 'info'];

    /** @var list<Diagnostic> */
    private array $entries = [];

    /**
     * @param DiagnosticSeverity $severity
     * @param DiagnosticStage    $stage
     *
     * @psalm-external-mutation-free
     */
    public function add(string $severity, string $stage, string $message): void
    {
        $this->entries[] = new Diagnostic($severity, $stage, $message);
    }

    /**
     * @param DiagnosticStage $stage
     *
     * @psalm-external-mutation-free
     */
    public function warning(string $stage, string $message): void
    {
        $this->add('warning', $stage, $message);
    }

    /**
     * Drain buffered diagnostics to $output, grouped by severity (most severe first)
     * and tagged with the lifecycle stage they came from. Entries are cleared after
     * replay, so a second flush is a no-op and no warning can ever surface twice.
     * A no-op when nothing was collected, so callers can flush unconditionally.
     */
    public function flushTo(Progress $output): void
    {
        foreach (self::SEVERITY_ORDER as $severity) {
            foreach ($this->entries as $entry) {
                if ($entry->severity === $severity) {
                    $output->warning("[{$entry->stage}] {$entry->message}");
                }
            }
        }

        $this->entries = [];
    }
}
