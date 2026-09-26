<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Taint;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Eval_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\BeforeExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\BeforeFileAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AddRemoveTaintsEvent;
use Psalm\Plugin\EventHandler\Event\BeforeExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\BeforeFileAnalysisEvent;
use Psalm\Plugin\EventHandler\RemoveTaintsInterface;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\TaintKind;

/**
 * Strips ALL taint from a named-argument VALUE in the one shape whose taint node upstream still
 * mis-keys: the callee's VARIADIC takes the argument, and the argument's WRITTEN offset lands on a
 * different, non-variadic parameter (residual vimeo/psalm#11923).
 *
 * `DataFlowNode::getParameterOffset()` keys an argument's node by the DECLARED index of the
 * parameter it binds to, so `sink(label: $tainted)` reaches `$label`'s sink and not the sink on
 * whatever parameter sits at offset 0. It returns the written offset instead for a variadic
 * parameter, so a collision needs both a variadic binding and a non-variadic parameter declared at
 * that offset. Neither condition alone is enough, which is why
 * {@see isMisattributedVariadicCapture} tests both against vendor source.
 *
 * Everything else is PRESERVED, including a named argument on a callee this handler cannot
 * resolve: upstream's attribution does not depend on the plugin seeing the signature, so an
 * unresolvable callee is no reason to doubt it. The strip that does fire removes
 * {@see TaintKind::ALL_INPUT} rather than the kind a given sink cares about, because a mis-routed
 * node can resurface as an arbitrary kind at an arbitrary sink. That is broad but NOT total:
 * `ALL_INPUT` excludes the secret kinds and any kind a project defines itself, so a mis-routed
 * secret or custom taint is still reported against the wrong parameter.
 *
 * Three upstream defects are deliberately left alone. All are to be filed against vimeo/psalm;
 * none is fixable here.
 *
 * 1. SPREAD FAN-OUT. `Action::run(page: $input)` forwarding `mixed ...$arguments` onto
 *    `handle(?string $directory, int $page)` reports TaintedFile against `$directory`. The offset
 *    there IS the variadic's own index, so the call-site node is keyed correctly and this handler
 *    preserves it; the imprecision is born one hop later, where the spread fans out. It is
 *    spelling-independent — `forward(...['page' => $p])` emits the identical finding with no named
 *    argument anywhere — so suppressing the named spelling only hid one spelling of it, and hid the
 *    genuine finding when the argument's true destination is itself a sink. Reported as
 *    psalm/psalm-plugin-laravel#1395; accepted here.
 * 2. SHARED INHERITANCE EDGE. `ArgumentAnalyzer` passes a call site's `removed_taints` into the
 *    `Contract::method#n -> Impl::method#n` edge, and `DataFlowGraph::addPath()` ASSIGNS
 *    `forward_edges[$from][$to]` rather than merging it. So a strip at one call site can erase a
 *    DIFFERENT call site's genuine finding through the same interface hop, and which one wins
 *    depends on declaration order. This makes the strip's blast radius wider than the call site it
 *    fires on, for every `RemoveTaintsInterface` handler, not just this one.
 * 3. SUBCLASS VARIADIC THROUGH `static::`. A child declaring a variadic where its parent does not
 *    resolves to the parent, whose parameter the argument names, so the capture is invisible and
 *    the mis-attribution survives. Prevalence unmeasurable; direction is a retained FP.
 *
 * Retirement: once `getParameterOffset()` threads the matched parameter's declared index through
 * its variadic branch too, this handler has nothing left to correct and can be deleted outright
 * — there is no partial field to gate on, unlike
 * {@see \Psalm\LaravelPlugin\Handlers\Eloquent\WhereColumnTaintHandler}.
 */
final class NamedArgumentTaintHandler implements
    BeforeExpressionAnalysisInterface,
    RemoveTaintsInterface,
    BeforeFileAnalysisInterface
{
    /**
     * Each variadic-captured named argument's VALUE node, consumed by
     * {@see removeTaints}. Weakly keyed for the same reason as
     * {@see \Psalm\LaravelPlugin\Handlers\Eloquent\WhereColumnTaintHandler::$whereColumnArguments}
     * (foreign ASTs can be parsed and freed mid-file, reissuing the object handle) and flushed
     * per file, not per function-like, for the same record-to-read-gap reason documented there.
     *
     * @psalm-var \WeakMap<object, true>|null
     */
    private static ?\WeakMap $namedArgumentValues = null;

    /**
     * @return \WeakMap<object, true>
     *
     * @psalm-pure
     */
    private static function newValueMap(): \WeakMap
    {
        /** @psalm-var \WeakMap<object, true> $map */
        $map = new \WeakMap();

        return $map;
    }

    /**
     * Records the value node of every named argument provably mis-keyed by a variadic capture.
     * Never short-circuits.
     */
    #[\Override]
    public static function beforeExpressionAnalysis(BeforeExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();

        if (!$expr instanceof FuncCall
            && !$expr instanceof MethodCall
            && !$expr instanceof NullsafeMethodCall
            && !$expr instanceof StaticCall
            && !$expr instanceof New_
        ) {
            return null;
        }

        // Gate on the taint run via the Codebase property, mirroring WhereColumnTaintHandler:
        // a plain type-check run has no taint graph to poison, so skip the work entirely.
        if (!$event->getCodebase()->taint_flow_graph instanceof \Psalm\Internal\Codebase\TaintFlowGraph) {
            return null;
        }

        // getArgs() throws on a first-class callable (`sink(...)`), which carries no named args.
        if ($expr->isFirstClassCallable()) {
            return null;
        }

        // `false` until the first named argument is seen: a call without one must not pay for
        // a storage lookup, and `null` is already a meaningful result (callee unresolvable).
        $params = false;

        foreach ($expr->getArgs() as $offset => $arg) {
            $name = $arg->name;

            // getArgs() is docblocked `Arg[]`, which Psalm reads as array-key-keyed; real call
            // args are int-indexed, and the offset has to line up with the int-keyed $params.
            if (!$name instanceof Identifier || !\is_int($offset)) {
                continue;
            }

            if ($params === false) {
                $params = self::resolveDeclaredParams($expr, $event);
            }

            // An unresolvable callee preserves: upstream attributes a named argument by declared
            // index whether or not the plugin can read the signature. Never record a node Psalm's
            // own core dispatches AddRemoveTaintsEvent against ({@see isSelfDispatchedSinkSubject}).
            if ($params === null
                || !self::isMisattributedVariadicCapture($params, $offset, $name->name)
                || self::isSelfDispatchedSinkSubject($arg->value)
            ) {
                continue;
            }

            (self::$namedArgumentValues ??= self::newValueMap())->offsetSet($arg->value, true);
        }

        return null;
    }

    /**
     * True when upstream binds this named argument to the callee's VARIADIC *and* keys the
     * resulting node onto a different, non-variadic parameter. Both halves are required, and each
     * mirrors one piece of vendor source rather than paraphrasing it.
     *
     * The COLLISION half, `DataFlowNode::getParameterOffset()`: it returns `$fallback` (the
     * written offset) for a variadic parameter and the declared index otherwise. So the node is
     * mis-keyed only when the written offset lands on a parameter that is itself NOT variadic. An
     * offset landing on the variadic's own declared index — `f(cmd: $x)` on `f(string ...$rest)`,
     * or `g('a', 'b', zzz: $x)` on `g($a, $b, ...$rest)` — is keyed exactly as a positional call
     * would be, and so is an offset past every declared parameter. Stripping either is pure loss.
     *
     * The BINDING half, `ArgumentsAnalyzer::checkArgumentsMatch()`: it scans in declaration order
     * and breaks on the first parameter satisfying `name === $arg->name || is_variadic`, so the
     * variadic takes the argument BOTH when no parameter carries the name AND when the argument
     * names the variadic itself. `w(rest: 'X')` really does yield `$rest === ['rest' => 'X']` at
     * runtime, so the second route is not a technicality. Returning `$param->is_variadic` from the
     * first hit reproduces the break exactly; a scan that treated any name match as "not captured"
     * would miss it.
     *
     * @param list<FunctionLikeParameter> $params
     *
     * @psalm-mutation-free
     */
    private static function isMisattributedVariadicCapture(array $params, int $offset, string $name): bool
    {
        $collidesWith = $params[$offset] ?? null;

        if (!$collidesWith instanceof FunctionLikeParameter || $collidesWith->is_variadic) {
            return false;
        }

        foreach ($params as $param) {
            if ($param->name === $name || $param->is_variadic) {
                return $param->is_variadic;
            }
        }

        return false;
    }

    /**
     * True when Psalm's own core separately dispatches `AddRemoveTaintsEvent` against this very
     * node for a reason unrelated to it being a named-argument value. Our `\WeakMap` matches by
     * node IDENTITY ({@see removeTaints}), so recording one of these would make our strip fire
     * on that unrelated dispatch too and erase a genuine, independent finding.
     *
     * Still reachable after the strip narrowed to variadic capture: `v(zzz: eval($input))`.
     *
     * `Eval_`/`Include_` are the vulnerability themselves, and a `FuncCall`/`New_` whose
     * callee/class expression is dynamic carries a `TaintKind::INPUT_CALLABLE` sink keyed to the
     * whole call node — all four dispatch on the node itself. `StaticCall` is deliberately
     * absent: its dispatch only applies the method's own `conditionally_removed_taints` to its
     * own return value, which is the value we mean to strip anyway.
     *
     * @psalm-mutation-free
     */
    private static function isSelfDispatchedSinkSubject(Expr $value): bool
    {
        if ($value instanceof Eval_ || $value instanceof Include_) {
            return true;
        }

        if ($value instanceof FuncCall && !$value->name instanceof Name) {
            return true;
        }

        return $value instanceof New_ && !$value->class instanceof Name;
    }

    /**
     * The callee's declared params, or `null` when the callee stays unresolvable — a dynamic
     * call/class, a receiver that is not one known class, or a name none of whose candidates
     * resolve. `null` preserves every named argument on the call: a variadic capture cannot be
     * proven without the signature, and anything unproven is attributed correctly upstream.
     *
     * @return list<FunctionLikeParameter>|null
     */
    private static function resolveDeclaredParams(
        FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_ $expr,
        BeforeExpressionAnalysisEvent $event,
    ): ?array {
        $statementsSource = $event->getStatementsSource();

        if (!$statementsSource instanceof StatementsAnalyzer) {
            return null;
        }

        foreach (self::resolveCalleeIdCandidates($expr, $event) as $functionId) {
            $params = self::resolveParamsForCandidate($functionId, $statementsSource, $event);

            if ($params !== null) {
                return $params;
            }
        }

        return null;
    }

    /**
     * One candidate id's params, or `null` if it does not resolve. `FunctionLikeStorage` first,
     * then a per-shape fallback for the two things it cannot see: a PHP-internal function has no
     * `FunctionStorage` at all (its param NAMES live only in the CallMap), and a facade
     * `@method` pseudo-method is skipped by `getFunctionLikeStorage()`'s `methodExists()` gate.
     * An overloaded builtin (`file_put_contents'1`, ...) picks the FIRST listed signature; a
     * misaligned pick costs at most an unproven variadic capture, not a crash.
     *
     * @param non-empty-string $functionId
     *
     * @return list<FunctionLikeParameter>|null
     */
    private static function resolveParamsForCandidate(
        string $functionId,
        StatementsAnalyzer $statementsSource,
        BeforeExpressionAnalysisEvent $event,
    ): ?array {
        try {
            return $event->getCodebase()->getFunctionLikeStorage($statementsSource, $functionId)->params;
        } catch (\Throwable) {
            // No FunctionStorage/MethodStorage under this candidate — fall through below.
        }

        if (\str_contains($functionId, '::')) {
            return self::pseudoMethodParams($functionId, $event); // never a CallMap entry.
        }

        try {
            $callables = \Psalm\Internal\Codebase\InternalCallMapHandler::getCallablesFromCallMap(
                \strtolower($functionId),
            );
        } catch (\Throwable) {
            return null;
        }

        return $callables[0]->params ?? null;
    }

    /**
     * A facade's `@method static` tag declares params but no real `MethodStorage`, and
     * {@see \Psalm\Codebase::getFunctionLikeStorage()} cannot see it — its `methodExists()`
     * gate leaves `$with_pseudo` false. Without this fallback a variadic capture on a facade call
     * cannot be proven, so the mis-attributed finding upstream produces for it survives.
     *
     * `pseudo_methods` is read for symmetry only: a stock Laravel codebase carries every
     * sink-bearing pseudo-method as a STATIC one. A class with both a real method and a
     * same-named `@method` tag never reaches here, because real storage resolves first.
     *
     * @param non-empty-string $methodId
     *
     * @return list<FunctionLikeParameter>|null
     *
     * @psalm-mutation-free
     */
    private static function pseudoMethodParams(string $methodId, BeforeExpressionAnalysisEvent $event): ?array
    {
        $separator = \strpos($methodId, '::');

        if ($separator === false) {
            return null;
        }

        try {
            $storage = $event->getCodebase()->classlike_storage_provider->get(\substr($methodId, 0, $separator));
        } catch (\InvalidArgumentException) {
            return null;
        }

        $method = \strtolower(\substr($methodId, $separator + 2));

        return ($storage->pseudo_static_methods[$method] ?? $storage->pseudo_methods[$method] ?? null)?->params;
    }

    /**
     * Candidate callee ids for a `FuncCall`'s function name or a `StaticCall`/`New_`'s
     * "Class::method" id, most-likely-correct first, or an empty list for anything not
     * statically nameable (a dynamic function/class expression, an anonymous class). A
     * `StaticCall`/`New_` class name always gets an eager `resolvedName`
     * ({@see resolveClassNamePart}'s docblock) so it yields at most one candidate; a `FuncCall`'s
     * function name can need up to three (see {@see functionNameCandidates}).
     *
     * @return list<non-empty-string>
     */
    private static function resolveCalleeIdCandidates(
        FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_ $expr,
        BeforeExpressionAnalysisEvent $event,
    ): array {
        if ($expr instanceof FuncCall) {
            return $expr->name instanceof Name ? self::functionNameCandidates($expr->name) : [];
        }

        if ($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall) {
            $class = self::resolveReceiverClass($expr, $event);

            return $class === null || !$expr->name instanceof Identifier ? [] : [$class . '::' . $expr->name->name];
        }

        if ($expr instanceof StaticCall) {
            if (!$expr->name instanceof Identifier || !$expr->class instanceof Name) {
                return [];
            }

            $class = self::resolveClassNamePart($expr->class, $event);

            return $class === null || $class === '' ? [] : [$class . '::' . $expr->name->name];
        }

        // New_: an anonymous class (`Class_`) or a dynamic class expression is not nameable here.
        if (!$expr->class instanceof Name) {
            return [];
        }

        $class = self::resolveClassNamePart($expr->class, $event);

        return $class === null || $class === '' ? [] : [$class . '::__construct'];
    }

    /**
     * The single class a method call's receiver is known to hold, or null. Only a plain
     * `$var` receiver already in scope resolves: the argument types are not inferred yet at
     * this pre-pass, but the receiver VARIABLE's own type is, because it was assigned by an
     * earlier statement, and it is the same type `MethodCallAnalyzer` resolves the call against,
     * so the two cannot disagree. A union or non-object receiver declines, per the house rule
     * that narrowing on anything but exactly one known class turns into false positives.
     *
     * An INTERSECTION also declines. `isSingle()` counts union members and an intersection is one
     * member, so it passes that check while `getSingleAtomic()` answers with the primary atomic
     * alone and hides the rest in `extra_types`. Resolving one component's parameters would let a
     * variadic in that component prove a capture and strip the shared argument node, erasing a
     * sibling component's correctly attributed finding — measured with a receiver typed
     * `VariadicWriter&HtmlWriter`, where the `html` sink on the fixed `$label` disappeared.
     * Proving the collision across every component is the only sound alternative and buys nothing
     * measurable, so declining is the answer the house rule already prescribes.
     *
     * A CHAINED receiver (`Storage::disk('local')->put(path: ...)`) is not a `Variable` and has
     * no entry to read, so the fluent form declines and preserves. That is the correct answer for
     * everything but a variadic capture, which the fluent form therefore keeps mis-attributed.
     *
     * @psalm-mutation-free
     */
    private static function resolveReceiverClass(
        MethodCall|NullsafeMethodCall $expr,
        BeforeExpressionAnalysisEvent $event,
    ): ?string {
        if (!$expr->var instanceof Variable || !\is_string($expr->var->name)) {
            return null;
        }

        $receiver = $event->getContext()->vars_in_scope['$' . $expr->var->name] ?? null;

        // getSingleAtomic() is an unchecked reset(), so a union would silently narrow to its
        // first member; isSingle() is what makes "exactly one known class" true.
        if ($receiver === null || !$receiver->isSingle()) {
            return null;
        }

        $atomic = $receiver->getSingleAtomic();

        if (!$atomic instanceof TNamedObject || $atomic->extra_types !== []) {
            return null;
        }

        return $atomic->value;
    }

    /**
     * A function name's candidate ids, most-confident first. An unqualified, unaliased call
     * inside a namespace is ambiguous until runtime (PHP tries the current namespace, then the
     * global function), so `SimpleNameResolver` leaves `resolvedName` unset and records the
     * in-namespace candidate under `namespacedName` instead. Neither alone is enough:
     * `namespacedName` misses every global function called unqualified from a namespace, and the
     * raw name alone would prefer the global over a real in-namespace function of the same short
     * name. {@see resolveParamsForCandidate} tries each in turn.
     *
     * Not `@psalm-mutation-free`: `Name::getAttribute()` is stubbed impure (PHP-Parser nodes are
     * mutable), even though this method never mutates anything.
     *
     * @return list<non-empty-string>
     */
    private static function functionNameCandidates(Name $name): array
    {
        $candidates = [];

        foreach (['resolvedName', 'namespacedName'] as $attribute) {
            /** @psalm-var ?string $value */
            $value = $name->getAttribute($attribute);

            if (\is_string($value) && $value !== '') {
                $candidates[] = $value;
            }
        }

        // toString() is stubbed non-empty-string, unlike the two free-form attributes above.
        $candidates[] = $name->toString();

        return \array_values(\array_unique($candidates));
    }

    /**
     * Resolves a class `Name` to an FQCN, handling `self`/`static`/`parent` against the
     * enclosing scope. Mirrors
     * {@see \Psalm\LaravelPlugin\Handlers\Eloquent\WhereColumnTaintHandler::resolveStaticClassName}.
     * Unlike a function name, an unqualified class name always gets an eager `resolvedName`
     * (classes have no runtime namespaced-then-global fallback), so no `namespacedName` fallback
     * is needed here.
     */
    private static function resolveClassNamePart(Name $name, BeforeExpressionAnalysisEvent $event): ?string
    {
        if ($name->isSpecialClassName()) {
            return match (\strtolower($name->toString())) {
                'self', 'static' => $event->getContext()->self,
                'parent' => $event->getContext()->parent,
                default => null,
            };
        }

        /** @psalm-var ?string $resolved */
        $resolved = $name->getAttribute('resolvedName');

        return \is_string($resolved) ? $resolved : $name->toString();
    }

    /**
     * Removes every INPUT taint kind from a recorded variadic-captured value node. See the class
     * docblock for why the removal is kind-agnostic, and for what `ALL_INPUT` leaves behind.
     */
    #[\Override]
    public static function removeTaints(AddRemoveTaintsEvent $event): int
    {
        $recorded = self::$namedArgumentValues;

        if (!$recorded instanceof \WeakMap || !$recorded->offsetExists($event->getExpr())) {
            return 0;
        }

        return TaintKind::ALL_INPUT;
    }

    /**
     * Flush the recorded value nodes at file START — see
     * {@see \Psalm\LaravelPlugin\Handlers\Eloquent\WhereColumnTaintHandler::beforeAnalyzeFile}
     * for why per-file (not per-function-like) is correct here too.
     *
     * The only branch here with no test: deleting this flush fails nothing, because a stale
     * record needs PHP to reissue a freed node's object handle, which no fixture can force.
     * WhereColumn's equivalent bug was found in the wild, so treat a change here as unguarded.
     *
     * @psalm-external-mutation-free
     */
    #[\Override]
    public static function beforeAnalyzeFile(BeforeFileAnalysisEvent $event): void
    {
        self::$namedArgumentValues = self::newValueMap();
    }
}
