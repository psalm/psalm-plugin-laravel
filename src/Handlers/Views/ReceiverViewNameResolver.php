<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Views;

use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar\String_;

/**
 * Pure AST walker that recovers the literal view name from a chained
 * view-builder receiver. Extracted from {@see BladeAwareViewTaintHandler} so
 * the dispatcher can share the same lookup without duplicating the
 * receiver-shape table.
 *
 * Resolvable shapes:
 *  - `view('home')->with(...)`
 *    – `FuncCall(name='view', args=[String('home'), ...])`
 *  - `View::make('home')->with(...)` or `app('view')->make('home')->with(...)`
 *    – `MethodCall(name='make', args=[String('home'), ...])` /
 *      `StaticCall(name='make', args=[String('home'), ...])`
 *  - `view('home')->with('a', 1)->with(...)` — recurse into the receiver's
 *    receiver for any chain of `with()` / `withErrors()` calls.
 *  - `View::first(['a', 'b'])->with(...)` — the sole literal in the array, or
 *    null when the array has multiple literals (see
 *    {@see self::firstLiteralFromArrayArg()}).
 *  - With `$extraViewBinders=['view','markdown','text']` (Mailable mode):
 *    `(new InvoiceMail)->view('mail.invoice')->with(...)` resolves to
 *    `'mail.invoice'`. The extra binders are both candidate-recording AND
 *    chain-preserving, so multi-binder chains
 *    (`view('a')->text('b')->with(...)`) accumulate every literal and trigger
 *    the count-based refusal in {@see self::resolve()}. In Mailable mode the
 *    resolver also recurses through any other method name (`subject`,
 *    `from`, `locale`, ...) without recording a candidate; production
 *    Mailable chains interleave those decorators freely between `view()`
 *    and `with()` and the strict stop-on-unknown rule of default View mode
 *    would silently lose the upstream binder.
 *
 * Soundness invariants:
 *  - never picks one literal when multiple are present in a `first()` array —
 *    Laravel's runtime semantics pick the first existing view, which is
 *    opaque at analysis time. Refusing resolution avoids a silent miss when
 *    the runtime falls back to a later, unsafe view.
 *  - never resolves through variables (variable-bound chains
 *    `$v = view('home'); $v->with(...)` were evaluated for PR-5 via a
 *    NodeTypeProvider tracker and rejected on performance grounds; see
 *    `docs/issues/581-blade-taint-exploration.md` "Key decisions during
 *    PR-5"). Bare-variable receivers return null.
 *  - returns null when the chain carries MORE THAN ONE view-binding method
 *    call (Mailable's `view('a')->text('b')` binds two slots; conservative
 *    refusal mirrors the multi-literal `View::first(['a', 'b'])` rule).
 *  - returns null for unsupported method names so callers know to fall
 *    through to whatever fallback policy the dispatcher chooses.
 *
 * @internal
 */
final class ReceiverViewNameResolver
{
    /**
     * The `view()` helper as registered in Laravel's global function table.
     * Compared against the lowercased function name on a `FuncCall` receiver.
     */
    private const FUNCTION_VIEW = 'view';

    /**
     * Sentinel pushed into the candidate list when a view-binder method
     * call is observed with a non-literal first argument
     * (`view($dynamic)`, `markdown($var)`, ...). View names cannot
     * contain a NUL byte, so this string can never collide with a real
     * candidate. {@see self::resolve()} treats any presence of the
     * sentinel as forced refusal — Laravel binds the dynamic value at
     * runtime ("last call wins" for `$this->view`), so a literal
     * candidate further up the chain could be silently overridden, and
     * picking it would be unsound.
     */
    private const NON_LITERAL_BINDER = "\x00non-literal-binder";

    /**
     * Seal the static-only contract. The class holds no instance state and
     * exposes only static methods; instantiation would be a programmer
     * error. {@see self::resolve()} is the single public entry point.
     *
     * @psalm-api
     *
     * @psalm-mutation-free
     */
    private function __construct() {}

    /**
     * Resolve the literal view name carried by a chained view-builder
     * receiver, or null when no single literal name can be safely picked.
     *
     * @param list<lowercase-string> $extraViewBinders extra MethodCall
     *   names that bind a view name (e.g. Mailable's `view`, `markdown`,
     *   `text`). Each occurrence records its literal first argument as a
     *   candidate AND recurses into the receiver, so multi-binder chains
     *   accumulate every literal. When the accumulated set has more than
     *   one entry, the resolver refuses (returns null). Default `[]` for
     *   the `View::with` registration.
     * @param bool                   $recurseThroughUnknownMethods when
     *   true, MethodCall sites whose name matches none of the recognised
     *   arms (`with`/`witherrors`/extras/`make`/`first`) are treated as
     *   chain-preserving and recursed into without recording a candidate.
     *   Set true for Mailable so the resolver walks through decorator
     *   methods like `->subject(...)`, `->from(...)`, `->locale(...)`
     *   etc. that production chains interleave between `view()` and
     *   `with()`. Set false (default) for `View::with` to preserve the
     *   pre-PR-6 strict stop-on-unknown rule — `Illuminate\View\View`
     *   has no decorate-without-binding-view surface, so recursing past
     *   an unknown method on the View side would weaken PR-3's precision
     *   policy without compensating gain.
     */
    public static function resolve(
        Expr $receiver,
        array $extraViewBinders = [],
        bool $recurseThroughUnknownMethods = false,
    ): ?string {
        $candidates = self::collectCandidates($receiver, $extraViewBinders, $recurseThroughUnknownMethods);

        // Exactly one literal binding AND no dynamic-binder sentinel: the
        // unambiguous view for this chain. Zero candidates means the
        // receiver carries no resolvable literal (variable-bound,
        // dynamic, unsupported shape). Two-or-more, or any
        // sentinel-marked entry, means multiple view-binding method
        // calls appeared in the chain (Mailable's `view('a')->text('b')`
        // / repeated `view('a')->view('b')` / mixed
        // `view('a')->view($dynamic)`); cannot pick a single template
        // safely, so refuse — same soundness rule as multi-literal
        // `View::first(['a', 'b'])`. The sentinel check is what makes
        // the mixed literal/dynamic case sound: without it, `view('a')
        // ->view($dynamic)` would silently resolve to 'a' even though
        // Laravel's runtime `$this->view = $dynamic` overrides 'a'.
        if (\count($candidates) !== 1) {
            return null;
        }

        if ($candidates[0] === self::NON_LITERAL_BINDER) {
            return null;
        }

        return $candidates[0];
    }

    /**
     * @param list<lowercase-string> $extraViewBinders
     *
     * @return list<string>
     */
    private static function collectCandidates(
        Expr $receiver,
        array $extraViewBinders,
        bool $recurseThroughUnknownMethods,
    ): array {
        // Treat nullsafe and regular method calls uniformly. We re-dispatch
        // by drilling into the same `(receiver, method-name, args)` triple
        // rather than constructing a synthetic MethodCall — keeps the walk
        // allocation-free and avoids touching php-parser constructors that
        // may move between minor versions.
        if ($receiver instanceof Expr\NullsafeMethodCall) {
            return self::collectFromMethodCall(
                $receiver->var,
                $receiver->name,
                $receiver->args,
                $extraViewBinders,
                $recurseThroughUnknownMethods,
            );
        }

        if ($receiver instanceof Expr\FuncCall) {
            $name = self::viewNameFromFuncCall($receiver);

            return $name !== null ? [$name] : [];
        }

        if ($receiver instanceof Expr\MethodCall) {
            return self::collectFromMethodCall(
                $receiver->var,
                $receiver->name,
                $receiver->args,
                $extraViewBinders,
                $recurseThroughUnknownMethods,
            );
        }

        if ($receiver instanceof Expr\StaticCall) {
            return self::collectFromStaticCall($receiver);
        }

        return [];
    }

    /**
     * Shared collection for `MethodCall` / `NullsafeMethodCall` receivers.
     * Both shapes carry the same `(receiver, method-name, args)` triple;
     * the caller pre-extracts them so this helper handles either node
     * type without allocating a synthetic intermediate.
     *
     * @param array<array-key, \PhpParser\Node\VariadicPlaceholder|Arg> $args
     * @param list<lowercase-string>                                    $extraViewBinders
     *
     * @return list<string>
     */
    private static function collectFromMethodCall(
        Expr $methodReceiver,
        \PhpParser\Node\Identifier|Expr $methodName,
        array $args,
        array $extraViewBinders,
        bool $recurseThroughUnknownMethods,
    ): array {
        if (!$methodName instanceof \PhpParser\Node\Identifier) {
            return [];
        }

        $methodNameLc = $methodName->toLowerString();

        // Chained `with()` / `withErrors()` (and similar) preserve the
        // view-builder identity without binding a view name themselves.
        // Recurse into the receiver's receiver.
        if ($methodNameLc === 'with' || $methodNameLc === 'witherrors') {
            return self::collectCandidates($methodReceiver, $extraViewBinders, $recurseThroughUnknownMethods);
        }

        // Mailable-style view-binders: BOTH record arg[0] as a candidate AND
        // recurse into the receiver. Every binder invocation contributes
        // an entry (literal name when arg[0] is a `String_`, the
        // {@see self::NON_LITERAL_BINDER} sentinel otherwise) so the
        // count-based refusal in `resolve()` covers BOTH multi-literal
        // chains (`view('a')->text('b')`) AND mixed literal/dynamic
        // chains (`view('a')->view($dynamic)`). The mixed case is the
        // load-bearing one: Laravel binds the dynamic value at runtime
        // ("last call wins" for `$this->view`), so an upstream literal
        // is not the unambiguous winner.
        if (\in_array($methodNameLc, $extraViewBinders, true)) {
            $candidates = self::collectCandidates($methodReceiver, $extraViewBinders, $recurseThroughUnknownMethods);
            $candidates[] = self::viewNameFromArg($args[0] ?? null) ?? self::NON_LITERAL_BINDER;

            return $candidates;
        }

        // Terminal builders. These do not chain off another view-builder
        // — `$factory->make(...)` / `$factory->first(...)` have a factory
        // receiver, not a view-builder receiver — so no further recursion.
        if ($methodNameLc === 'make') {
            $name = self::viewNameFromArg($args[0] ?? null);

            return $name !== null ? [$name] : [];
        }

        if ($methodNameLc === 'first') {
            $name = self::firstLiteralFromArrayArg($args[0] ?? null);

            return $name !== null ? [$name] : [];
        }

        // Mailable mode (caller passes $recurseThroughUnknownMethods=true):
        // recurse through any other instance method, treating it as
        // chain-preserving with no candidate contribution.
        // `Illuminate\Mail\Mailable` exposes ~40 methods that
        // `return $this` (`subject`, `from`, `to`, `cc`, `bcc`,
        // `replyTo`, `locale`, `priority`, `tag`, `metadata`, `theme`,
        // `attach`, `attachData`, `attachFromStorage`, ...). None bind
        // a view name. Realistic Mailable chains interleave these
        // freely, e.g.
        //   `(new InvoiceMail)->subject('Hi')->view('emails.invoice')
        //                     ->with('bio', $tainted)`.
        // Stopping at the first unknown method (the View::with default)
        // would silently lose the upstream `view()` binder on every
        // such chain. The toggle is independent of $extraViewBinders so
        // future callers can opt into either dial alone.
        if ($recurseThroughUnknownMethods) {
            return self::collectCandidates($methodReceiver, $extraViewBinders, $recurseThroughUnknownMethods);
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private static function collectFromStaticCall(Expr\StaticCall $call): array
    {
        if (!$call->name instanceof \PhpParser\Node\Identifier) {
            return [];
        }

        $methodNameLc = $call->name->toLowerString();

        // Static calls do not chain off a view-builder (the class side of
        // `::` is a class name, not an instance), so they are terminal-only.
        // Mailable's extra view binders do not apply: Laravel's Mailable
        // API exposes `view()` / `markdown()` / `text()` as instance
        // methods on a constructed Mailable, not as static calls.
        if ($methodNameLc === 'make') {
            $name = self::viewNameFromArg($call->args[0] ?? null);

            return $name !== null ? [$name] : [];
        }

        if ($methodNameLc === 'first') {
            $name = self::firstLiteralFromArrayArg($call->args[0] ?? null);

            return $name !== null ? [$name] : [];
        }

        return [];
    }

    private static function viewNameFromFuncCall(Expr\FuncCall $call): ?string
    {
        if (!$call->name instanceof \PhpParser\Node\Name) {
            return null;
        }

        if ($call->name->toLowerString() !== self::FUNCTION_VIEW) {
            return null;
        }

        return self::viewNameFromArg($call->args[0] ?? null);
    }

    /** @psalm-mutation-free */
    private static function viewNameFromArg(\PhpParser\Node\VariadicPlaceholder|Arg|null $arg): ?string
    {
        if (!$arg instanceof Arg) {
            return null;
        }

        return self::literalString($arg);
    }

    /**
     * Return the sole literal-string element of an array literal argument, or
     * null if the argument is not an array literal, contains no literal
     * strings, or contains MORE THAN ONE literal string.
     *
     * Used for `View::first(['a', 'b'])->with(...)` chains. Laravel's runtime
     * semantics for `first()` are "render the first existing view"; at
     * analysis time we cannot know which candidate exists, so picking ANY one
     * literal would be unsound for the receiver-walk's single-view lookup.
     * Consider:
     *
     *     view::first(['safe_layout', 'unsafe_show'])->with('html_body', $tainted);
     *
     * If `safe_layout` ships and `unsafe_show` doesn't, no XSS. If
     * `safe_layout` is later renamed and Laravel falls back to `unsafe_show`,
     * `$tainted` reaches a raw echo. Picking `safe_layout` for the with-
     * dispatcher's safety lookup would silently miss this regression.
     *
     * The conservative fix is to refuse resolution entirely for multi-
     * candidate arrays. `BladeAwareViewTaintHandler::dispatchFirstLike` takes
     * the union across all literals at the direct `Factory::first(...)` call
     * site; the receiver-walk path (chained `with()` off a `first()`
     * receiver) does not have an equivalent union mechanism wired here yet.
     * Treating the receiver as unresolvable is consistent with PR-3's "view
     * not in map → no sink" policy.
     *
     * @psalm-mutation-free
     */
    private static function firstLiteralFromArrayArg(\PhpParser\Node\VariadicPlaceholder|Arg|null $arg): ?string
    {
        if (!$arg instanceof Arg || $arg->unpack || !$arg->value instanceof Array_) {
            return null;
        }

        $resolved = null;

        foreach ($arg->value->items as $item) {
            if (!$item instanceof ArrayItem) {
                continue;
            }

            if (!$item->value instanceof String_) {
                // Non-literal candidate: the runtime view name is opaque, so
                // any earlier match we picked would be unsound. Bail.
                return null;
            }

            if ($resolved !== null) {
                // Second literal observed: cannot tell which Laravel will
                // pick. Conservatively refuse resolution.
                return null;
            }

            $resolved = $item->value->value;
        }

        return $resolved;
    }

    /**
     * Literal-string extractor scoped to {@see Arg}. Duplicated from the
     * handler so this resolver has no inbound dependency on
     * {@see BladeAwareViewTaintHandler}. Kept private — callers reach the
     * literal-name surface via {@see self::resolve()}.
     *
     * @psalm-mutation-free
     */
    private static function literalString(Arg $arg): ?string
    {
        if ($arg->value instanceof String_) {
            return $arg->value->value;
        }

        return null;
    }
}
