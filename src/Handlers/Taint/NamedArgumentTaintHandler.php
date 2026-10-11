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
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Analyzer\TraitAnalyzer;
use Psalm\Plugin\EventHandler\BeforeExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AddRemoveTaintsEvent;
use Psalm\Plugin\EventHandler\Event\BeforeExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\RemoveTaintsInterface;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Storage\MethodStorage;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\TaintKind;

/**
 * Strips taint from a named-argument value that Psalm binds to the callee's variadic parameter.
 *
 * Two Psalm 7.0.0-rc1 bugs misreport such an argument: an unpacked argument is mapped onto every
 * parameter ignoring string keys (vimeo/psalm#12252, the `run(...$args)` -> `handle(...$args)`
 * shape, #1395), and `getParameterOffset()` keys a variadic by its written offset, colliding with
 * the fixed parameter declared there (vimeo/psalm#12251). Every other named argument is already
 * attributed correctly (vimeo/psalm#11923 is fixed) and is left to Psalm.
 *
 * The strip is only applied when the callee is resolved and the dispatch is exact (so an
 * abstract or interface method is never stripped), and never inside a trait body: see
 * {@see isExactDispatch()} and {@see isInsideTrait()}.
 *
 * Accepted trade: the strip drops the argument's whole flow ({@see TaintKind::ALL_INPUT}), so a
 * genuine sink in the variadic's body or behind the re-spread is lost versus plain Psalm (pinned
 * by `TaintedNamedArgumentVariadicKnownLimitation.phpt`; rationale in decisions.md).
 *
 * Retirement: delete once both vimeo/psalm#12251 and #12252 are fixed.
 */
final class NamedArgumentTaintHandler implements BeforeExpressionAnalysisInterface, RemoveTaintsInterface
{
    /**
     * Value nodes of named arguments bound to a variadic, consumed by {@see removeTaints}. Weakly
     * keyed so entries die with their AST node. Each visit of a call rewrites its own verdict
     * (set or unset), so a re-analysis of the same node with a different receiver type cannot
     * leave a stale strip behind.
     *
     * @psalm-var \WeakMap<object, true>|null
     */
    private static ?\WeakMap $variadicCapturedValues = null;

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
     * Records named-argument values bound to a variadic. Each visit rewrites its own verdict so a
     * re-analysis of the same node under another callee cannot leave a stale strip. Never
     * short-circuits.
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

        // A plain type-check run has no taint graph to poison, so skip the work entirely.
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

        foreach ($expr->getArgs() as $arg) {
            $name = $arg->name;

            if (!$name instanceof Identifier) {
                continue;
            }

            if ($params === false) {
                $params = self::resolveDeclaredParams($expr, $event);
            }

            if ($params !== null
                && self::bindsToVariadic($params, $name->name)
                && !self::isSelfDispatchedSinkSubject($arg->value)
            ) {
                (self::$variadicCapturedValues ??= self::newValueMap())->offsetSet($arg->value, true);
            } else {
                self::$variadicCapturedValues?->offsetUnset($arg->value);
            }
        }

        return null;
    }

    /**
     * Mirrors Psalm's matcher: the first parameter with `name === $arg->name || is_variadic` takes
     * the argument, so the variadic captures an unmatched name and one naming the variadic itself.
     *
     * @param list<FunctionLikeParameter> $params
     *
     * @psalm-mutation-free
     */
    private static function bindsToVariadic(array $params, string $name): bool
    {
        foreach ($params as $param) {
            if ($param->name === $name || $param->is_variadic) {
                return $param->is_variadic;
            }
        }

        return false;
    }

    /**
     * Psalm separately dispatches `AddRemoveTaintsEvent` on these nodes for their own sinks
     * (`eval`, `include`, a dynamic callee or class), and the WeakMap matches by node identity, so
     * recording one would erase its genuine `TaintedEval`/`TaintedInclude`/`TaintedCallable`.
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
     * The callee's params, or null (leave the call to Psalm) when it is unresolvable, a CallMap
     * builtin or facade pseudo-method (no storage), written in a trait, or inexact.
     *
     * @return list<FunctionLikeParameter>|null
     */
    private static function resolveDeclaredParams(
        FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_ $expr,
        BeforeExpressionAnalysisEvent $event,
    ): ?array {
        $statementsSource = $event->getStatementsSource();

        if (!$statementsSource instanceof StatementsAnalyzer || self::isInsideTrait($statementsSource)) {
            return null;
        }

        foreach (self::resolveCalleeIdCandidates($expr, $event) as $functionId) {
            try {
                $storage = $event->getCodebase()->getFunctionLikeStorage($statementsSource, $functionId);
            } catch (\Throwable) {
                // No FunctionStorage/MethodStorage under this candidate: try the next one.
                continue;
            }

            // An abstract or interface method is only ever reached through an inexact call (a
            // final class cannot be abstract), so the exactness check also covers it.
            if ($storage instanceof MethodStorage && !self::isExactDispatch($expr, $functionId, $storage, $event)) {
                return null;
            }

            return $storage->params;
        }

        return null;
    }

    /**
     * True when the call provably reaches the resolved method. An instance call or `static::`
     * only has an upper bound (a subclass may override with fixed parameters before the
     * variadic), so it counts for a final class, enum or final method, or a private method on an
     * instance call (a private method does not pin `static::`: PHP dispatches it to the
     * late-bound class's public method). `Class::`, `self::`, `parent::` and `new Class` are exact.
     *
     * @param non-empty-string $functionId
     *
     * @psalm-mutation-free
     */
    private static function isExactDispatch(
        FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_ $expr,
        string $functionId,
        MethodStorage $method,
        BeforeExpressionAnalysisEvent $event,
    ): bool {
        $instanceCall = $expr instanceof MethodCall || $expr instanceof NullsafeMethodCall;
        $lateBound = $instanceCall
            || (($expr instanceof StaticCall || $expr instanceof New_)
                && $expr->class instanceof Name
                && \strtolower($expr->class->toString()) === 'static');

        if (!$lateBound) {
            return true;
        }

        if ($method->final || ($instanceCall && $method->visibility === ClassLikeAnalyzer::VISIBILITY_PRIVATE)) {
            return true;
        }

        $separator = \strpos($functionId, '::');

        if ($separator === false) {
            return false;
        }

        try {
            $class = $event->getCodebase()->classlike_storage_provider->get(\substr($functionId, 0, $separator));
        } catch (\InvalidArgumentException) {
            return false;
        }

        return $class->final || $class->is_enum;
    }

    /**
     * True when the call is written inside a trait method. Psalm analyses a trait body once per
     * using class over the same AST nodes and shares expression-internal taint edges between
     * those visits, so a strip for one user erases a genuine finding of another.
     */
    private static function isInsideTrait(StatementsAnalyzer $statementsSource): bool
    {
        $source = $statementsSource->getSource();

        // The chain ends at the FileAnalyzer, whose getSource() is itself.
        while ($source !== $source->getSource()) {
            if ($source instanceof TraitAnalyzer) {
                return true;
            }

            $source = $source->getSource();
        }

        return false;
    }

    /**
     * Callee ids ("Class::method" or function names), most-likely first; empty when the callee
     * is not statically nameable (dynamic name, anonymous class).
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
     * The single class a `$var` receiver holds, or null. The variable's type is already in scope
     * at this pre-pass (argument types are not). A chained or property receiver has no entry and
     * declines; a nullsafe call resolves because Psalm re-dispatches it on a virtual variable.
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

        // An intersection is one union member whose other components live in `extra_types`;
        // trusting the primary component alone would erase a sibling component's finding.
        if (!$atomic instanceof TNamedObject || $atomic->extra_types !== []) {
            return null;
        }

        return $atomic->value;
    }

    /**
     * An unqualified call inside a namespace is ambiguous until runtime, so `resolvedName` is
     * unset and the in-namespace candidate sits in `namespacedName`; the global name is the last
     * resort. Not `@psalm-mutation-free`: `Name::getAttribute()` is stubbed impure.
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
     * `self`/`static`/`parent` resolve against the enclosing scope; an unqualified class name
     * always has an eager `resolvedName`, so no `namespacedName` fallback is needed.
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
     * Kind-agnostic: a mis-attributed node can resurface as any kind at any sink.
     */
    #[\Override]
    public static function removeTaints(AddRemoveTaintsEvent $event): int
    {
        $recorded = self::$variadicCapturedValues;

        if (!$recorded instanceof \WeakMap || !$recorded->offsetExists($event->getExpr())) {
            return 0;
        }

        return TaintKind::ALL_INPUT;
    }
}
