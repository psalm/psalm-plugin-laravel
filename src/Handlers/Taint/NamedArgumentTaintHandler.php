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
use Psalm\LaravelPlugin\Handlers\Validation\ValidationRuleAnalyzer;
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
 * Strips ALL taint from a named-argument VALUE unless Psalm can be trusted to attribute it to
 * the parameter the argument actually names — a stop-gap for vimeo/psalm#11923.
 *
 * Upstream builds the argument's taint node id from its WRITTEN offset
 * (`ArgumentsAnalyzer::checkArgumentsMatch()` stores the matched param under the positional
 * key) while sinks are keyed by DECLARED parameter index, so `sink(label: $tainted)` reports
 * against whatever parameter sits at offset 0. Type checking is unaffected; only the taint
 * graph mis-routes. Reported as psalm/psalm-plugin-laravel#1395.
 *
 * A named argument is preserved only when the callee is statically resolvable AND the declared
 * parameter at the argument's own written offset already carries the argument's name — the one
 * shape upstream gets right. Everything else is stripped, and the strip is total (every input
 * kind via {@see ValidationRuleAnalyzer::allInputTaints()}, not the kind a given sink cares
 * about) because a mis-routed node can resurface as an arbitrary kind at an arbitrary sink.
 *
 * That trade is FN-over-FP by design. The resulting false-negative surface — variadic capture,
 * late static binding, re-entrant file analysis, callees that stay unresolvable — is enumerated
 * in psalm/psalm-plugin-laravel#1406 rather than repeated here.
 *
 * Retirement: once upstream threads the matched parameter's declared index into
 * `DataFlowNode::getForMethodArgument()` instead of the written offset, this handler has
 * nothing left to correct and can be deleted outright — there is no partial field to gate on,
 * unlike {@see \Psalm\LaravelPlugin\Handlers\Eloquent\WhereColumnTaintHandler}.
 */
final class NamedArgumentTaintHandler implements
    BeforeExpressionAnalysisInterface,
    RemoveTaintsInterface,
    BeforeFileAnalysisInterface
{
    /**
     * Each named argument's VALUE node recorded as mis-attributable, consumed by
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
     * Records the value node of every named argument whose name does not provably match the
     * declared parameter at its own written offset. Never short-circuits.
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

            $param = $params[$offset] ?? null;

            // Skip when upstream already attributes this argument correctly (its name equals the
            // declared parameter at its own written offset), and never record a node Psalm's own
            // core dispatches AddRemoveTaintsEvent against ({@see isSelfDispatchedSinkSubject}).
            if (($param instanceof FunctionLikeParameter && $param->name === $name->name)
                || self::isSelfDispatchedSinkSubject($arg->value)
            ) {
                continue;
            }

            (self::$namedArgumentValues ??= self::newValueMap())->offsetSet($arg->value, true);
        }

        return null;
    }

    /**
     * True when Psalm's own core separately dispatches `AddRemoveTaintsEvent` against this very
     * node for a reason unrelated to it being a named-argument value. Our `\WeakMap` matches by
     * node IDENTITY ({@see removeTaints}), so recording one of these would make our strip fire
     * on that unrelated dispatch too and erase a genuine, independent finding.
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
     * resolve. `null` records every named argument on the call, the safe (strip) direction.
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
     * misaligned pick falls through to the accepted-strip default, not a crash.
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
     * gate leaves `$with_pseudo` false. Without this fallback `Storage::get(path: ...)` and
     * every other facade named-argument call counted as "unresolvable" and had its taint
     * stripped, losing genuine findings upstream attributes correctly.
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
     * A CHAINED receiver (`Storage::disk('local')->put(path: ...)`) is not a `Variable` and has
     * no entry to read, so the fluent form still strips while the variable form does not. There
     * is no receiver type at this pre-pass to fix that with; tracked in #1406.
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

        return $atomic instanceof TNamedObject ? $atomic->value : null;
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
     * Removes every taint kind from a recorded named-argument value node. See the class
     * docblock for why the strip is total rather than kind-scoped.
     *
     * @return list<string>
     */
    #[\Override]
    public static function removeTaints(AddRemoveTaintsEvent $event): array
    {
        $recorded = self::$namedArgumentValues;

        if (!$recorded instanceof \WeakMap || !$recorded->offsetExists($event->getExpr())) {
            return [];
        }

        return ValidationRuleAnalyzer::allInputTaints();
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
