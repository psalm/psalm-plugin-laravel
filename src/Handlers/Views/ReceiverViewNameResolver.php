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
 * the tracker handler (PR-5+) can share the same lookup without duplicating
 * the receiver-shape table.
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
 *
 * PR-5 introduces {@see BladeViewBindingTracker} which attaches a synthetic
 * `parent_nodes` marker to the {@see \Psalm\Type\Union} returned by
 * `view()` / `Factory::make()` etc. The tracker is preferred at the dispatch
 * call site because it covers variable-bound chains
 * (`$v = view('home'); $v->with(...)`); this AST walker remains the literal
 * fallback when the tracker has no marker (e.g. before any handler runs).
 *
 * Soundness invariants:
 *  - never picks one literal when multiple are present in a `first()` array —
 *    Laravel's runtime semantics pick the first existing view, which is
 *    opaque at analysis time. Refusing resolution avoids a silent miss when
 *    the runtime falls back to a later, unsafe view.
 *  - never resolves through variables (covered by the tracker, not here).
 *  - returns null for unsupported `StaticCall` method names so callers know
 *    to fall through to the whole-data sink path.
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
     * Seal the static-only contract. The class holds no instance state and
     * exposes only static methods; instantiation would be a programmer
     * error. {@see self::resolve()} is the single public entry point.
     *
     * @psalm-api
     *
     * @psalm-mutation-free
     */
    private function __construct() {}

    public static function resolve(Expr $receiver): ?string
    {
        // Treat nullsafe and regular method calls uniformly. We re-dispatch
        // by drilling into the same shape rather than constructing a fresh
        // MethodCall node (which would trigger Psalm's purity guards on the
        // node constructor).
        if ($receiver instanceof Expr\NullsafeMethodCall) {
            return self::resolveMethodCallReceiver($receiver->var, $receiver->name, $receiver->args);
        }

        if ($receiver instanceof Expr\FuncCall) {
            return self::viewNameFromFuncCall($receiver);
        }

        if ($receiver instanceof Expr\MethodCall) {
            return self::resolveMethodCallReceiver($receiver->var, $receiver->name, $receiver->args);
        }

        if ($receiver instanceof Expr\StaticCall) {
            if (!$receiver->name instanceof \PhpParser\Node\Identifier) {
                return null;
            }

            $methodName = $receiver->name->toLowerString();

            if ($methodName === 'make') {
                return self::viewNameFromArg($receiver->args[0] ?? null);
            }

            if ($methodName === 'first') {
                return self::firstLiteralFromArrayArg($receiver->args[0] ?? null);
            }

            return null;
        }

        return null;
    }

    /**
     * Shared resolution for `MethodCall` / `NullsafeMethodCall` receivers.
     * Both shapes carry the same `(receiver, method-name, args)` triple; the
     * caller pre-extracts them so this helper can run on either node type
     * without constructing intermediate AST nodes (which would breach Psalm
     * purity guards on the php-parser constructors).
     *
     * @param array<array-key, \PhpParser\Node\VariadicPlaceholder|Arg> $args
     */
    private static function resolveMethodCallReceiver(
        Expr $methodReceiver,
        \PhpParser\Node\Identifier|Expr $methodName,
        array $args,
    ): ?string {
        if (!$methodName instanceof \PhpParser\Node\Identifier) {
            return null;
        }

        $methodNameLc = $methodName->toLowerString();

        // Chained `with()` / `withErrors()` (and similar) preserve the
        // view-builder identity. Recurse into the receiver's receiver to
        // recover the underlying view name.
        if ($methodNameLc === 'with' || $methodNameLc === 'witherrors') {
            return self::resolve($methodReceiver);
        }

        if ($methodNameLc === 'make') {
            return self::viewNameFromArg($args[0] ?? null);
        }

        if ($methodNameLc === 'first') {
            return self::firstLiteralFromArrayArg($args[0] ?? null);
        }

        return null;
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
