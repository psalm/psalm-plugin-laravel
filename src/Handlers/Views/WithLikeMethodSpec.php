<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Views;

/**
 * Argument-shape descriptor for `\Illuminate\View\View::with($key, $value)`
 * (and the matching contract method) — Laravel's chained data-binding
 * primitive.
 *
 * `with()` differs from {@see MakeLikeMethodSpec} in two ways:
 *
 *  - The data shape is `($key, $value)` — a single key/value pair, not an
 *    associative array. The handler dispatches one sink per call site.
 *  - The view name is NOT in the argument list. It lives on the receiver:
 *    `view('home')->with('user', $user)` carries the view name `'home'` on
 *    the `MethodCall::$var` expression. The dispatcher walks that
 *    sub-expression to recover the view name. Receivers that PR-4 can
 *    statically resolve:
 *      * `view(string)` global function call
 *      * `_::make(string)` / `_::first([string])` direct call on the
 *        `\Illuminate\View\Factory` (or facade) class — first literal view
 *        wins for `first`
 *
 *    Receivers we cannot resolve (variable-bound chains
 *    `$v = view('home'); $v->with(...)`, dynamic view names, etc.) skip the
 *    sink entirely. PR-5 will add a NodeTypeProvider attachment that
 *    propagates view names through return-type metadata for the
 *    variable-bound case.
 *
 * Sink dispatch rules:
 *
 *  - If the receiver's view name resolves AND the key is a literal that
 *    matches the resolved template's unsafe-keys set, install an `html` sink
 *    on `$value` keyed by the literal `$key`.
 *  - If the template is UNKNOWN or the key is non-literal, install a
 *    whole-arg sink on `$value` (single key, single value — the "whole-data"
 *    fallback collapses to one sink here).
 *  - If the template is SAFE, install no sink.
 *  - If the receiver does not resolve, install no sink (consistent with
 *    PR-3's "view not in map → no sink" policy).
 *
 * @internal
 *
 * @psalm-immutable
 */
final readonly class WithLikeMethodSpec implements ViewBindingSinkSpec
{
    public function __construct(
        public int $keyArgIndex,
        public int $valueArgIndex,
    ) {}
}
