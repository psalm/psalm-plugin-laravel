<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Taint;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
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
use Psalm\Type\TaintKind;

/**
 * Strips ALL taint from a named-argument VALUE when Psalm cannot be trusted to attribute it to
 * the right declared parameter — a stop-gap for vimeo/psalm#11923.
 *
 * ## Upstream bug
 *
 * In `ArgumentsAnalyzer::checkArgumentsMatch()` a named argument is resolved to the correct
 * declared parameter for type-checking, but the taint node built for it (`ArgumentAnalyzer.php`
 * ~:1774-1791, `DataFlowNode::getForMethodArgument(..., $argument_offset, ...)`) is keyed by the
 * argument's WRITTEN position instead. Sinks are keyed by declared parameter index, so
 * `sink(label: tainted())` on `sink(string $path, string $label)` reports on `$path` (false
 * positive) and nothing on `$label` (false negative). Type checking is unaffected — only the
 * taint graph mis-routes. Reported as psalm/psalm-plugin-laravel#1395.
 *
 * ## Design consequence
 *
 * When a named argument's name does NOT match the parameter at its own written offset, every
 * finding Psalm currently produces through it is already mis-attributed — stripping loses no
 * genuine detection. Only the case where the name happens to equal its position works correctly
 * today ({@see resolveDeclaredParams} + the name/offset comparison in
 * {@see beforeExpressionAnalysis} preserve exactly that case). Everything else is stripped
 * entirely ({@see TaintKind::ALL_INPUT}, not just the kind a given sink cares about) because the
 * mis-routed node can resurface as an arbitrary taint kind at an arbitrary sink downstream.
 *
 * A `MethodCall`/`NullsafeMethodCall` receiver's type is not resolved yet at this pre-pass (this
 * hook fires before the receiver is descended into), so there is no cheap way to look up the
 * declared parameter here. Every named argument on those call kinds is recorded unconditionally
 * — an accepted false negative, matching this stop-gap's cheap-heuristic-first, FN-over-FP
 * posture.
 *
 * Retirement: once upstream threads the matched parameter's declared index into
 * `DataFlowNode::getForMethodArgument()` instead of the written offset, this handler has nothing
 * left to correct and can be deleted outright — there is no partial field to gate on, unlike
 * {@see \Psalm\LaravelPlugin\Handlers\Eloquent\WhereColumnTaintHandler}.
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

        /** @var array<int, Arg> $namedArgs */
        $namedArgs = [];

        // Arg::getArgs() is docblocked `Arg[]` (Psalm reads that as array-key-keyed), but a call's
        // arguments are always positionally int-indexed in practice — the is_int() check narrows
        // the key so it can index the equally int-offset-keyed $params list below.
        foreach ($expr->getArgs() as $offset => $arg) {
            if (\is_int($offset) && $arg->name instanceof Identifier) {
                $namedArgs[$offset] = $arg;
            }
        }

        if ($namedArgs === []) {
            return null;
        }

        $params = self::resolveDeclaredParams($expr, $event);

        foreach ($namedArgs as $offset => $arg) {
            $name = $arg->name;

            if (!$name instanceof Identifier) {
                continue; // re-narrowed for Psalm; already guaranteed true by the loop above.
            }

            $param = $params[$offset] ?? null;

            // Upstream already attributes this one correctly (its name equals the declared
            // parameter at its own written offset) — nothing to strip. See the class docblock.
            if ($param instanceof FunctionLikeParameter && $param->name === $name->name) {
                continue;
            }

            (self::$namedArgumentValues ??= self::newValueMap())->offsetSet($arg->value, true);
        }

        return null;
    }

    /**
     * The callee's declared params for a `FuncCall`/`StaticCall`/`New_` with a statically
     * resolvable name, or `null` when unresolvable (a dynamic call/class, an unpopulated
     * storage, or — always — a `MethodCall`/`NullsafeMethodCall`; see the class docblock). `null`
     * makes every named argument on this call record itself, the safe (strip-taint) direction.
     *
     * @return list<FunctionLikeParameter>|null
     */
    private static function resolveDeclaredParams(
        FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_ $expr,
        BeforeExpressionAnalysisEvent $event,
    ): ?array {
        if ($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall) {
            return null;
        }

        $functionId = self::resolveCalleeId($expr, $event);

        if ($functionId === null || $functionId === '') {
            return null;
        }

        $statementsSource = $event->getStatementsSource();

        if (!$statementsSource instanceof StatementsAnalyzer) {
            return null;
        }

        try {
            return $event->getCodebase()->getFunctionLikeStorage($statementsSource, $functionId)->params;
        } catch (\Throwable) {
            // Storage genuinely unresolvable (unpopulated classlike, unknown function, ...) —
            // treat exactly like an unresolved callee rather than risk a crash on this
            // best-effort probe.
            return null;
        }
    }

    /**
     * Resolves a `FuncCall`'s function name or a `StaticCall`/`New_`'s "Class::method" id, or
     * `null` for anything not statically nameable (a dynamic function/class expression, an
     * anonymous class).
     */
    private static function resolveCalleeId(
        FuncCall|StaticCall|New_ $expr,
        BeforeExpressionAnalysisEvent $event,
    ): ?string {
        if ($expr instanceof FuncCall) {
            return $expr->name instanceof Name ? self::resolveFunctionNamePart($expr->name) : null;
        }

        if ($expr instanceof StaticCall) {
            if (!$expr->name instanceof Identifier || !$expr->class instanceof Name) {
                return null;
            }

            $class = self::resolveClassNamePart($expr->class, $event);

            return $class === null ? null : $class . '::' . $expr->name->name;
        }

        // New_: an anonymous class (`Class_`) or a dynamic class expression is not nameable here.
        if (!$expr->class instanceof Name) {
            return null;
        }

        $class = self::resolveClassNamePart($expr->class, $event);

        return $class === null ? null : $class . '::__construct';
    }

    /**
     * A `FuncCall`'s name only gets an eager `resolvedName` when it is aliased or already fully
     * qualified — an unqualified, unaliased function call inside a namespace is genuinely
     * ambiguous until runtime (PHP tries the current namespace first, then falls back to the
     * global function), so `SimpleNameResolver` leaves `resolvedName` unset and records the
     * namespaced CANDIDATE under `namespacedName` instead. Preferred over the raw written name so
     * an in-namespace function (like this stop-gap's own test fixtures) resolves; a bare global
     * function called from inside a namespace fails that candidate and falls through to the
     * `\Throwable` catch in {@see resolveDeclaredParams} as unresolved — the safe direction.
     *
     * Not `@psalm-mutation-free`: `Name::getAttribute()` is stubbed impure (PHP-Parser nodes are
     * mutable), even though this method itself never mutates anything.
     */
    private static function resolveFunctionNamePart(Name $name): string
    {
        /** @psalm-var ?string $resolved */
        $resolved = $name->getAttribute('resolvedName');

        if (\is_string($resolved)) {
            return $resolved;
        }

        /** @psalm-var ?string $namespaced */
        $namespaced = $name->getAttribute('namespacedName');

        return \is_string($namespaced) ? $namespaced : $name->toString();
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
     * @psalm-external-mutation-free
     */
    #[\Override]
    public static function beforeAnalyzeFile(BeforeFileAnalysisEvent $event): void
    {
        self::$namedArgumentValues = self::newValueMap();
    }
}
