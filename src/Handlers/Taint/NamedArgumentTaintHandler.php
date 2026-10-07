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
use Psalm\Plugin\EventHandler\Event\AddRemoveTaintsEvent;
use Psalm\Plugin\EventHandler\Event\BeforeExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\RemoveTaintsInterface;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Storage\MethodStorage;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\TaintKind;

/**
 * Strips taint from a named-argument VALUE that Psalm binds to the callee's VARIADIC parameter.
 *
 * Why, two Psalm 7.0.0-rc1 defects that both surface as spurious reports:
 *
 * - Re-spread (vimeo/psalm#12252, `ArgumentsAnalyzer` unpacking). A variadic that forwards its
 *   arguments (`function run(string ...$arguments)` calling `handle(...$arguments)`, the
 *   `AsAction::run()` shape of lorisleiva/laravel-actions) makes Psalm map the unpacked argument
 *   onto EVERY parameter of `handle()` from its offset to the end, ignoring the array's string
 *   keys. `run(page: $input)` therefore reports against `handle()`'s first parameter (a spurious
 *   `TaintedFile` for `File::files($directory)`) although `$input` only reaches `$page` (#1395).
 * - Written-offset key (vimeo/psalm#12251, `DataFlowNode::getParameterOffset()`). For a variadic
 *   parameter it returns the argument's WRITTEN offset, so a named argument bound to the variadic
 *   collides with whatever fixed parameter is declared at that offset. This needs no re-spread:
 *   `v(zzz: $input)` on `v(string $a = '', string ...$rest)` reports against `$a`'s sink.
 *
 * Everything else is PRESERVED. Psalm keys a named argument's taint node by the parameter's
 * DECLARED index (`getParameterOffset()`, vimeo/psalm#11923 fixed), so reordered, skipped,
 * inherited and `self::`/`parent::` named arguments already reach the right sink. Not handled
 * here, all left to Psalm: first-class callables of plain functions (vimeo/psalm#12249), CallMap
 * return flows read by written offset (#12248) and `HtmlFunctionTainter` reading `flags`
 * positionally (#12250). Stubbed variadic builtins (`sprintf`, `array_merge`) do resolve storage
 * and are stripped; PHP rejects unknown named arguments to them anyway, so no real finding is lost.
 *
 * Binding mirrors `ArgumentsAnalyzer::checkArgumentsMatch()`: the FIRST declared parameter
 * satisfying `name === $arg->name || is_variadic` takes the argument, so the variadic captures
 * both an unmatched name and an argument naming the variadic itself. Anything binding to a
 * non-variadic parameter, even one declared before a variadic, is preserved.
 *
 * SCOPE: only a callee resolved here is covered: a function name, `Class::`/`self::`/`static::`/
 * `parent::`, `new`, or a plain `$var` receiver already typed as exactly one non-intersection
 * class. A chained (`Action::make()->run(page: $x)`) or property (`$this->action->run(...)`)
 * receiver is not resolved, so the #1395 false positive stays visible there. Abstract and
 * interface methods are skipped: with no body there is nothing to re-spread.
 *
 * KNOWN LIMITATIONS (accepted trade). The strip is kind-agnostic ({@see TaintKind::ALL_INPUT})
 * and kills the argument's whole source flow at the call site, so versus plain Psalm it loses
 * genuine findings; it only equals the handler this replaced. `ALL_INPUT` excludes secret and
 * project-defined kinds, which are not stripped. Each loss is pinned by a fixture:
 *
 * - a genuine sink behind the re-spread (`handle()`'s `$page` really reaches a sink):
 *   `TaintedNamedArgumentVariadicRespreadGenuineDestinationKnownLimitation.phpt`;
 * - a sink in the variadic's own body (`foreach ($rest as $r) system($r)`) for `zzz:` or an
 *   argument naming the variadic: `TaintedNamedArgumentVariadicBodySinkKnownLimitation.phpt`;
 * - `static::s(sink: ...)` where the enclosing class's `s()` is variadic and a subclass overrides
 *   it with fixed parameters, since `static` cannot be resolved here:
 *   `TaintedNamedArgumentStaticOverrideVariadicParentKnownLimitation.phpt`;
 * - the unresolved chained/property receiver above:
 *   `TaintedNamedArgumentChainedReceiverVariadicRespreadKnownLimitation.phpt`.
 *
 * The rejected alternative, dropping the strip and reporting the false positive, is recorded in
 * decisions.md: Psalm offers no hook that removes only the mis-attributed flows.
 *
 * Retirement: delete once BOTH vimeo/psalm#12251 and #12252 are fixed.
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
     * Records the value node of every named argument that binds to the callee's variadic.
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
     * True when Psalm binds a named argument to the VARIADIC: its matcher scans in declaration
     * order and breaks on the first parameter with `name === $arg->name || is_variadic`, so the
     * variadic wins both when no earlier parameter carries the name and when the argument names
     * the variadic itself (`w(rest: 'X')` really yields `$rest === ['rest' => 'X']` at runtime).
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
     * True when Psalm's own core separately dispatches `AddRemoveTaintsEvent` against this very
     * node for a reason unrelated to it being a named-argument value. The `\WeakMap` matches by
     * node IDENTITY ({@see removeTaints}), so recording one of these would make the strip fire
     * on that unrelated dispatch too and erase a genuine, independent finding
     * (`v(zzz: eval($input))` would lose `TaintedEval`).
     *
     * `Eval_`/`Include_` are the vulnerability themselves, and a `FuncCall`/`New_` whose
     * callee/class expression is dynamic carries a `TaintKind::INPUT_CALLABLE` sink keyed to the
     * whole call node: all four dispatch on the node itself. `StaticCall` is deliberately
     * absent: its dispatch only applies the method's own `conditionally_removed_taints` to its
     * own return value, which is the value the strip means to remove anyway.
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
     * The callee's declared params, or `null` when the callee stays unresolvable: a dynamic
     * call/class, a receiver that is not one known class, a name none of whose candidates
     * resolve, an abstract or interface method (no body to re-spread), or a callee without
     * `FunctionLikeStorage` (a CallMap-only builtin or a facade `@method` pseudo-method).
     * `null` preserves every named argument on the call.
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
            try {
                $storage = $event->getCodebase()->getFunctionLikeStorage($statementsSource, $functionId);
            } catch (\Throwable) {
                // No FunctionStorage/MethodStorage under this candidate: try the next one.
                continue;
            }

            // An abstract or interface method has no body that could re-spread the variadic, so
            // there is no spread false positive to silence, while a concrete override with fixed
            // parameters can hold a genuine sink for the very same argument.
            if ($storage instanceof MethodStorage && self::isBodiless($storage, $event)) {
                return null;
            }

            return $storage->params;
        }

        return null;
    }

    /**
     * Psalm leaves `MethodStorage::$abstract` false for an interface method, so the declaring
     * class is consulted as well.
     */
    private static function isBodiless(MethodStorage $storage, BeforeExpressionAnalysisEvent $event): bool
    {
        if ($storage->abstract) {
            return true;
        }

        if ($storage->defining_fqcln === null) {
            return false;
        }

        try {
            return $event->getCodebase()->classlike_storage_provider->get($storage->defining_fqcln)->is_interface;
        } catch (\InvalidArgumentException) {
            return false;
        }
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
     * A CHAINED (`Storage::disk('local')->put(path: ...)`) or property (`$this->disk->put(...)`)
     * receiver is not a `Variable` and has no entry to read, so it declines and the call is left
     * to Psalm. A nullsafe call is the exception: Psalm re-dispatches it as a `MethodCall` on a
     * virtual variable that holds the receiver's type, so it can resolve like a plain variable.
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

        // An intersection is ONE union member whose other components live in `extra_types`, so
        // `isSingle()` passes while `getSingleAtomic()` answers with the primary component alone.
        // Trusting that component's variadic would strip the shared argument node and erase a
        // sibling component's correctly attributed finding; Psalm's choice of primary component
        // is not the written order, so declining is the only stable answer.
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
     * name. {@see resolveDeclaredParams} tries each in turn.
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
     * Removes every INPUT taint kind from a recorded variadic-captured value node. The removal is
     * kind-agnostic because a mis-attributed node can resurface as any kind at any sink.
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
