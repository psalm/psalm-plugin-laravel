<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Views;

/**
 * Argument-shape descriptor for `Factory::renderEach($view, $data, $iterator,
 * $empty = 'raw|')`.
 *
 * `renderEach` is structurally different from {@see MakeLikeMethodSpec}: the
 * `$data` argument is an iterable of elements, not an associative array
 * extracted into the template, and the literal string `$iterator` names the
 * variable each element is bound to in the rendered child template.
 *
 * Sink dispatch rules (see {@see BladeAwareViewTaintHandler}):
 *
 *  - Resolve the child template via the literal `$view` name.
 *  - If the template is SAFE, install no sink (the iterator's value cannot
 *    reach a raw echo).
 *  - If the template is UNSAFE_KEYS and `$iterator` is a literal whose value
 *    appears in the child's unsafe-keys set, install an `html` sink on
 *    `$data`. Each element flows to the named variable, so sinking the data
 *    argument catches every element-level taint.
 *  - If `$iterator` is non-literal, the child binds an unknown variable
 *    name and the handler cannot prove the iterator does NOT match an unsafe
 *    key; fall back to the whole-data sink on `$data`.
 *  - If the template is UNKNOWN (cycles, unparsable blocks, etc.), apply the
 *    whole-data sink regardless of `$iterator`.
 *  - Dynamic `$view` falls back identically to other dispatch shapes
 *    (whole-data sink with the documented `DynamicViewName` policy).
 *
 * @internal
 *
 * @psalm-immutable
 */
final readonly class RenderEachLikeMethodSpec implements ViewBindingSinkSpec
{
    public function __construct(
        public int $viewArgIndex,
        public int $dataArgIndex,
        public int $iteratorArgIndex,
    ) {}
}
