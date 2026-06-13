<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Views;

/**
 * Argument-shape descriptor for `\Illuminate\View\View::with($key, $value)`
 * (and the matching contract method) — Laravel's chained data-binding
 * primitive. Also serves `\Illuminate\Mail\Mailable::with($key, $value)`,
 * which has the same `(key, value)` shape but a different chain head
 * (`Mailable::view`/`markdown`/`text` instead of `view()`/`Factory::make()`);
 * the Mailable variant is configured via {@see self::$extraViewBinders}.
 *
 * `with()` differs from {@see MakeLikeMethodSpec} in two ways:
 *
 *  - The data shape is `($key, $value)` — a single key/value pair, not an
 *    associative array. The handler dispatches one sink per call site.
 *  - The view name is NOT in the argument list. It lives on the receiver:
 *    `view('home')->with('user', $user)` carries the view name `'home'` on
 *    the `MethodCall::$var` expression. The dispatcher walks that
 *    sub-expression via {@see ReceiverViewNameResolver} to recover the view
 *    name. Receivers that resolve statically:
 *      * `view(string)` global function call
 *      * `_::make(string)` / `_::first([string])` direct call on the
 *        `\Illuminate\View\Factory` (or facade) class — first literal view
 *        wins for `first`
 *      * (Mailable mode only, via `$extraViewBinders`)
 *        `(new InvoiceMail)->view('mail.invoice')` / `->markdown(...)` /
 *        `->text(...)` returning `$this`, optionally with intervening
 *        chain-preserving `with()` calls.
 *
 *    Receivers we cannot resolve (variable-bound chains
 *    `$v = view('home'); $v->with(...)`, dynamic view names, chains with
 *    more than one view-binding method call in Mailable mode) skip the
 *    sink entirely. See {@see ReceiverViewNameResolver} for the soundness
 *    invariants and `docs/issues/581-blade-taint-exploration.md` for the
 *    deferred-tracker reasoning behind the variable-bound gap.
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
    /**
     * @param list<lowercase-string> $extraViewBinders extra MethodCall
     *   names that bind a view to the receiver chain (record arg[0] as
     *   a candidate AND recurse through their own receiver). Passed
     *   straight through to {@see ReceiverViewNameResolver::resolve()}.
     *   Empty for the default `\Illuminate\View\View::with` registration;
     *   `['view','markdown','text']` for the
     *   `\Illuminate\Mail\Mailable::with` registration so Mailable's
     *   view-binders close the chain. Class gating happens at the
     *   spec-registration site (Mailable's `with` is registered against
     *   `\Illuminate\Mail\Mailable::class` only); the resolver itself
     *   stays class-agnostic.
     * @param bool                   $recurseThroughUnknownMethods when
     *   true, the resolver treats MethodCall sites whose name matches
     *   none of the recognised arms (`with`/`witherrors`/extras/`make`/
     *   `first`) as chain-preserving and recurses into the receiver.
     *   Independent of `$extraViewBinders` so future callers can opt
     *   into either dial alone. True for Mailable (chains routinely
     *   interleave `->subject()`, `->from()`, `->locale()` decorators
     *   between `view()` and `with()`); false for `View::with` (the
     *   View API has no decorate-without-binding-view surface; PR-3's
     *   precision policy benefits from strict stop-on-unknown).
     */
    public function __construct(
        public int $keyArgIndex,
        public int $valueArgIndex,
        public array $extraViewBinders = [],
        public bool $recurseThroughUnknownMethods = false,
    ) {}
}
