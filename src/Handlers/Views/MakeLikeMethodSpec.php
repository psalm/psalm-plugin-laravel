<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Views;

/**
 * Argument shape descriptor for `Factory::make()`-style methods consumed by
 * {@see BladeAwareViewTaintHandler}.
 *
 * `Factory::make($view, $data = [], $mergeData = [])` is the only shape PR-3
 * dispatches on, but the descriptor is a small object rather than a tuple so
 * PR-4+ can add `first()` (where the view argument is `array<string>`) or
 * `renderEach()` (where `$data` is a collection and an extra `$iterator` arg
 * names the per-item variable) without changing the call sites in the handler.
 *
 * @internal
 *
 * @psalm-immutable
 */
final readonly class MakeLikeMethodSpec
{
    public function __construct(
        public int $viewArgIndex,
        public int $dataArgIndex,
        public ?int $mergeDataArgIndex,
    ) {}
}
