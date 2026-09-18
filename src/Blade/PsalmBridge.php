<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Psalm\Codebase;
use Psalm\Internal\Codebase\TaintFlowGraph;

/**
 * The single place the Blade pipeline touches Psalm internals that differ between Psalm 6 and
 * Psalm 7. Every other Blade class works in terms of these methods, so porting the pipeline to the
 * Psalm 7 line is a rewrite of this file rather than a hunt through the package.
 *
 * Each method carries the Psalm 6 file:line it was read off. Citations are against the Psalm the
 * branch requires (`vendor/vimeo/psalm`, 6.17.x); re-read them before trusting one on another line.
 *
 * @internal
 */
final class PsalmBridge
{
    /**
     * Whether Psalm is running taint analysis.
     *
     * Psalm 6 builds `Codebase::$taint_flow_graph` only under `--taint-analysis`
     * (`vendor/vimeo/psalm/src/Psalm/Codebase.php:173`, nullable and null by default), and in that
     * mode `IssueBuffer::add()` discards every non-`Tainted*` issue. Psalm 7 runs taint by default
     * and reports both kinds from one run, so there the answer is not a mode at all.
     */
    public static function isTaintRun(Codebase $codebase): bool
    {
        return $codebase->taint_flow_graph instanceof TaintFlowGraph;
    }

}
