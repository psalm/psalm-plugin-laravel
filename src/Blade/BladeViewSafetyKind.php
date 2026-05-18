<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Tri-state safety classification for a single Blade template.
 *
 * A flat list of unsafe keys cannot represent the difference between
 * "we scanned this template and it has no raw output" (SAFE) and
 * "we saw something we cannot model soundly" (UNKNOWN). Conflating them is a
 * security smell: an UNKNOWN template silently treated as SAFE would suppress
 * the very `TaintedHtml` reports the integration is supposed to surface.
 *
 * Downstream handlers branch on this:
 *  - SAFE         -> do nothing
 *  - UNSAFE_KEYS  -> add per-key sinks for {@see BladeViewSafety::$unsafeKeys}
 *  - UNKNOWN      -> apply the configured fallback policy (sink the whole
 *                    data argument, skip, or report — depending on plugin
 *                    config and the {@see BladeUncertaintyReason} list)
 *
 * @psalm-api
 */
enum BladeViewSafetyKind
{
    /** Scanned end-to-end; no unescaped top-level data flow found. */
    case Safe;

    /** Scanned end-to-end; a finite set of top-level keys reaches raw output. */
    case UnsafeKeys;

    /** Scanner saw a construct it cannot model soundly. See uncertainties for reasons. */
    case Unknown;
}
