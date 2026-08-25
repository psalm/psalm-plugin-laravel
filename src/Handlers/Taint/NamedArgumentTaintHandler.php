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
 * declared parameter for type-checking, but the taint node id built for it (`ArgumentAnalyzer.php`
 * ~:1774-1791, `DataFlowNode::getForMethodArgument(..., $argument_offset, $function_param->location, ...)`)
 * mixes the two: the id uses the argument's WRITTEN offset, while the reported LOCATION uses the
 * correctly-matched parameter. Sinks are keyed by declared parameter index, so the id mismatch
 * routes the taint to the wrong sink's `taint_sink_params` entry — but a matching sink at the
 * RIGHT parameter still reports there, using the right location, if one is declared. Concretely,
 * for `sink(string $a, string $b)` with BOTH params sunk, `sink(b: tainted())` correctly reports
 * `TaintedFile` located at `$b`'s own declaration — the id mismatch only matters when the sinks
 * at the two positions differ (as in the false positive: `sink(string $path, string $label)`
 * with `$path` sunk `file` and `$label` sunk `html`, `sink(label: tainted())` reports on `$path`
 * instead of `$label`). So a mismatched-offset named argument does NOT always lose a genuine
 * finding — it is stripped anyway because this handler cannot tell the two shapes apart from the
 * AST alone (no method id or per-parameter sink map is available at
 * {@see BeforeExpressionAnalysisEvent} / {@see AddRemoveTaintsEvent} time), which is why the
 * false-negative exposure below is real, not merely theoretical. Type checking is unaffected —
 * only the taint graph mis-routes. Reported as psalm/psalm-plugin-laravel#1395.
 *
 * ## False-negative surface (accepted, cheap-heuristic-first / FN-over-FP)
 *
 * Only the case where a named argument's name equals the declared parameter at its own written
 * offset is preserved ({@see resolveDeclaredParams} + the name/offset comparison in
 * {@see beforeExpressionAnalysis}); every other named argument is stripped entirely
 * ({@see TaintKind::ALL_INPUT}, not just the kind a given sink cares about, because the
 * mis-routed node can resurface as an arbitrary kind at an arbitrary sink). Concretely that is:
 *
 * - Every named argument on a `MethodCall`/`NullsafeMethodCall`: its receiver's type is not
 *   resolved yet at this pre-pass (the hook fires before the receiver is descended into), so
 *   there is no cheap way to look up the declared parameter.
 * - Every named argument whose callee resolves to a PHP-internal (CallMap-only) function once
 *   {@see resolveDeclaredParams}'s stub-storage lookup AND its CallMap fallback both miss —
 *   `getFunctionLikeStorage()` throws for those (they have no `FunctionStorage`), so a candidate
 *   id that also fails `InternalCallMapHandler::getCallablesFromCallMap()` (an unusual internal
 *   name, an unresolvable candidate) falls through to "unresolvable" and strips.
 * - Any callee this handler cannot statically name at all (a dynamic function/class expression).
 *
 * A corpus scan across 18 real Laravel apps (5,718 MethodCall/NullsafeMethodCall/StaticCall
 * named-argument sites, 329 distinct method names; ~550 FuncCall named-argument sites, 65
 * distinct function names) found none of the top 40 method names by frequency, nor any of the
 * 65 FuncCall names — checked exhaustively, since there are only 65 — landing on a taint sink;
 * the long tail of the remaining ~289 less-frequent method names was not individually checked
 * against the sink list. So the exposure is architectural, not realised today as far as this
 * scan reached, but it is not the exhaustive "loses nothing" guarantee an earlier draft of this
 * docblock claimed.
 *
 * Residual: the raw/global candidate ({@see functionNameCandidates}) could in principle read a
 * PHP builtin's param names for a namespaced userland function of the same short name — but
 * only when BOTH the `resolvedName` and `namespacedName` candidates already miss storage first,
 * which requires that userland function's own storage lookup to fail too. Not reproduced.
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
     * resolvable name, or `null` when unresolvable (a dynamic call/class, a name none of whose
     * candidates resolve to storage or a CallMap entry, or — always — a
     * `MethodCall`/`NullsafeMethodCall`; see the class docblock). `null` makes every named
     * argument on this call record itself, the safe (strip-taint) direction.
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
     * One candidate id's params, or `null` if this candidate does not resolve at all. Tries
     * `FunctionLikeStorage` first (userland functions, methods, `New_`), then — only for a plain
     * function candidate (no `::`) — the internal CallMap, because a PHP-internal function like
     * `file_put_contents()` has no `FunctionStorage` at all: `Functions::getStorage()`
     * (`Reflection::hasFunction()` gate, vendor Functions.php ~:112-124) throws for it, while its
     * declared param NAMES live only in `InternalCallMapHandler::getCallablesFromCallMap()`. An
     * overloaded builtin (`file_put_contents'1`, ...) picks the FIRST listed signature; a
     * misaligned pick just falls through to the accepted-strip default, not a crash.
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
            return null; // a method id is never a CallMap entry.
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
        FuncCall|StaticCall|New_ $expr,
        BeforeExpressionAnalysisEvent $event,
    ): array {
        if ($expr instanceof FuncCall) {
            return $expr->name instanceof Name ? self::functionNameCandidates($expr->name) : [];
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
     * A `FuncCall`'s name only gets an eager `resolvedName` when it is aliased or already fully
     * qualified — an unqualified, unaliased function call inside a namespace is genuinely
     * ambiguous until runtime (PHP tries the current namespace first, then falls back to the
     * global function), so `SimpleNameResolver` leaves `resolvedName` unset and records the
     * namespaced CANDIDATE under `namespacedName` instead.
     *
     * Neither candidate alone is enough: `namespacedName` misses every PHP-internal/global
     * function called unqualified from inside a namespace (`file_put_contents(...)` inside
     * `namespace App;` only has the useless candidate `App\file_put_contents`), and skipping
     * straight to the raw written name would wrongly prefer the global function over a real
     * in-namespace one of the same short name. So all three are tried, in order of confidence:
     * `resolvedName` (aliased/FQN — authoritative when present), then `namespacedName` (the
     * in-namespace candidate), then the raw written name (the global-fallback candidate PHP
     * itself would try last). {@see resolveParamsForCandidate} tries each in turn; a name that
     * matches none of the callee's real candidates falls through to the accepted-strip default.
     *
     * Not `@psalm-mutation-free`: `Name::getAttribute()` is stubbed impure (PHP-Parser nodes are
     * mutable), even though this method itself never mutates anything.
     *
     * @return list<non-empty-string>
     */
    private static function functionNameCandidates(Name $name): array
    {
        $candidates = [];

        /** @psalm-var ?string $resolved */
        $resolved = $name->getAttribute('resolvedName');

        if (\is_string($resolved) && $resolved !== '') {
            $candidates[] = $resolved;
        }

        /** @psalm-var ?string $namespaced */
        $namespaced = $name->getAttribute('namespacedName');

        if (\is_string($namespaced) && $namespaced !== '' && !\in_array($namespaced, $candidates, true)) {
            $candidates[] = $namespaced;
        }

        // Name::toString() is stubbed non-empty-string, unlike the two free-form attributes above.
        $raw = $name->toString();

        if (!\in_array($raw, $candidates, true)) {
            $candidates[] = $raw;
        }

        return $candidates;
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
