<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Views;

/**
 * Argument-shape descriptor for `Factory::first(array $views, $data = [],
 * $mergeData = [])`. Unlike {@see MakeLikeMethodSpec}, the view argument is
 * itself an `array<string>` of candidate view names (Laravel renders the
 * first that exists).
 *
 * Sink dispatch rules (see {@see BladeAwareViewTaintHandler}):
 *
 *  - If the views array is a literal array of literal strings, the handler
 *    looks up each view's safety record and takes the union of unsafe keys
 *    across the templates. The union is sound because `Factory::first` calls
 *    `Factory::make` on whichever existing view it finds, and analysis-time
 *    cannot tell which will exist at runtime.
 *  - If ANY listed template is UNKNOWN (or unknown to the map), the call
 *    falls back to the whole-data sink. The unsafe-key union is only sound
 *    when every contributing template has an enumerable key set.
 *  - If the views argument is not a literal array (e.g. `$views`), the
 *    handler applies the dynamic-name fallback identical to `Factory::make`
 *    with a dynamic first argument: whole-data sink on `$data` and
 *    `$mergeData`.
 *
 * @internal
 *
 * @psalm-immutable
 */
final readonly class FirstLikeMethodSpec implements ViewBindingSinkSpec
{
    public function __construct(
        public int $viewsArrayArgIndex,
        public int $dataArgIndex,
        public ?int $mergeDataArgIndex,
    ) {}
}
