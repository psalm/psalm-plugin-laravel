<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Views;

use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Internal\Codebase\TaintFlowGraph;
use Psalm\Internal\DataFlow\DataFlowNode;
use Psalm\LaravelPlugin\Blade\BladeSafetyMap;
use Psalm\LaravelPlugin\Blade\BladeViewSafety;
use Psalm\LaravelPlugin\Blade\BladeViewSafetyKind;
use Psalm\Plugin\EventHandler\AfterFunctionCallAnalysisInterface;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterFunctionCallAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Type\TaintKind;
use Psalm\Type\Union;

/**
 * Bridge {@see BladeSafetyMap} into Psalm's taint flow graph for `view()` /
 * `View::make()` / `Factory::make()` / `Factory::renderWhen()` /
 * `Factory::renderUnless()` call sites.
 *
 * Per-call refinement rules (see PR #872 and PR-3 brief in issue #581):
 *
 *  - SAFE         — do nothing. The template auto-escapes every echo of its
 *                   top-level data keys, so passing tainted html through is
 *                   not exploitable. No sink is installed.
 *  - UNSAFE_KEYS  — install an `html` taint sink on the data-array entry for
 *                   each key the scanner observed reaching a raw echo
 *                   ({!! ... !!} or @php).
 *  - UNKNOWN      — install an `html` taint sink on every entry of the data
 *                   array. The scanner could not prove which keys are safe
 *                   (cross-template flow, unparsable @php, file unreadable),
 *                   so all keys must be treated as potentially raw output.
 *  - dynamic name — same fallback as UNKNOWN. A non-literal view name means
 *                   the call site cannot resolve the template at analysis
 *                   time, so every key is conservatively tainted. Matches
 *                   {@see \Psalm\LaravelPlugin\Blade\BladeUncertaintyReason::DynamicViewName}.
 *
 * A view absent from the map (typo, package view not under the configured
 * roots) gets no sink. {@see MissingViewHandler} already reports the typo
 * separately; double-reporting through taint would be noise. Both handlers
 * coexist by design — the missing-view diagnostic and the taint refinement
 * answer different questions about the same call site.
 *
 * `$mergeData` (the third argument on `view()` / `Factory::make()`) feeds the
 * same `extract()` call as `$data`, so it is subjected to identical rules:
 * the keys observed by the scanner apply to either source.
 *
 * Out of scope (handled in PR-4 onwards, tracked under issue #581):
 *  - `@include('child', [...])` propagation into the parent template's safety
 *    record. The scanner flags any include as UNKNOWN, so the conservative
 *    fallback already covers it; PR-4 will narrow this.
 *  - Component data flow (`<x-foo :bar="$data" />`).
 *  - `Factory::first(array $views, ...)` and `Factory::renderEach()`. `first()`
 *    needs a union over multiple template safety records; `renderEach()` has a
 *    different argument shape (`$data` is a collection, `$iterator` names the
 *    per-item variable, the template echoes `$iterator` not data-array keys).
 *    Both fold into the same dispatch helper but require extra wiring beyond
 *    the scope of this PR.
 *  - `ResponseFactory::view()`. Same shape as `Factory::make()` but on a
 *    different service class; will follow once PR-3 stabilises the per-call
 *    sink construction.
 *
 * Stability: the taint sink construction uses Psalm's internal classes
 * {@see TaintFlowGraph} and {@see DataFlowNode}, both `@internal` in
 * `vimeo/psalm`. The plugin already depends on these for the validation taint
 * removal path; if Psalm 7.x changes the API, the validation handler will
 * also break, and the fix lands in both places at once.
 *
 * @internal
 */
final class BladeAwareViewTaintHandler implements
    AfterFunctionCallAnalysisInterface,
    AfterMethodCallAnalysisInterface
{
    /**
     * The `view()` helper as registered in Laravel's global function table.
     * Compared against the lowercased form of
     * {@see AfterFunctionCallAnalysisEvent::getFunctionId()}.
     *
     * Psalm preserves the call-site casing in `function_id` (only the leading
     * backslash is stripped), so we lower-case explicitly before comparing to
     * cover `\View(...)` / `\VIEW(...)` and any other legal-but-unusual casing.
     * Most Laravel codebases write `view(...)`, but the normalisation is cheap
     * insurance against a missed taint sink when a stray uppercase slips in.
     */
    private const FUNCTION_VIEW = 'view';

    /**
     * Synthetic stable id used as the `method_id` for our generated sink nodes.
     * The id is intentionally NOT a real Laravel method — that would collide
     * with sinks installed elsewhere (e.g. by future taint annotations on
     * `Factory::make`). The constant prefix plus the per-call CodeLocation
     * specialisation produces unique node ids per call site.
     */
    private const SINK_METHOD_ID = 'laravel-blade-view-data';

    private const SINK_CASED_METHOD_ID = 'Laravel\\Blade\\ViewData';

    /**
     * The data-key sink uses argument offset 0 because the synthetic
     * "function" has only one logical parameter: the value being rendered.
     * The DataFlowNode label encodes the per-key identity via the cased id
     * suffix; offset is just plumbing for {@see DataFlowNode::getForMethodArgument()}.
     */
    private const SINK_ARG_OFFSET = 0;

    private static ?BladeSafetyMap $map = null;

    /**
     * Method-id → argument shape descriptor. Built once at init from
     * {@see \Illuminate\View\Factory::class} plus its facade classes (so calls
     * like `\View::make()` route through the same dispatch). Keyed by the
     * lower-cased method id Psalm emits in {@see AfterMethodCallAnalysisEvent::getMethodId()}.
     *
     * @var array<lowercase-string, MakeLikeMethodSpec>
     */
    private static array $methodSpecs = [];

    private static bool $enabled = false;

    /**
     * Initialise the handler with the safety map and the set of facade classes
     * that proxy to {@see \Illuminate\View\Factory}. Idempotent: callers may
     * invoke this multiple times during a single analysis run.
     *
     * @param list<class-string> $factoryFacadeClasses additional class names whose
     *                                                 `::make()` should be treated
     *                                                 as a Blade view call site
     *
     * @psalm-external-mutation-free
     */
    public static function init(BladeSafetyMap $map, array $factoryFacadeClasses = []): void
    {
        self::$map = $map;
        self::$enabled = true;
        self::$methodSpecs = self::buildMethodSpecs($factoryFacadeClasses);
    }

    /**
     * Exposed for tests; production code re-initialises by calling
     * {@see self::init()} again. Marked `@psalm-api` so the self-analysis
     * pass does not flag it as unused: the only call site lives under
     * `tests/`, which is excluded from `composer psalm`.
     *
     * @internal
     *
     * @psalm-api
     * @psalm-external-mutation-free
     */
    public static function reset(): void
    {
        self::$map = null;
        self::$methodSpecs = [];
        self::$enabled = false;
    }

    /** @inheritDoc */
    #[\Override]
    public static function afterFunctionCallAnalysis(AfterFunctionCallAnalysisEvent $event): void
    {
        if (!self::$enabled || !self::$map instanceof \Psalm\LaravelPlugin\Blade\BladeSafetyMap) {
            return;
        }

        if (\strtolower($event->getFunctionId()) !== self::FUNCTION_VIEW) {
            return;
        }

        $taintGraph = $event->getCodebase()->taint_flow_graph;

        if (!$taintGraph instanceof \Psalm\Internal\Codebase\TaintFlowGraph) {
            return;
        }

        $args = $event->getExpr()->getArgs();

        if ($args === []) {
            return;
        }

        self::dispatchSinks(
            map: self::$map,
            viewArg: $args[0],
            dataArg: $args[1] ?? null,
            mergeDataArg: $args[2] ?? null,
            source: $event->getStatementsSource(),
            codebase: $event->getCodebase(),
            taintGraph: $taintGraph,
        );
    }

    /** @inheritDoc */
    #[\Override]
    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        if (!self::$enabled || !self::$map instanceof \Psalm\LaravelPlugin\Blade\BladeSafetyMap) {
            return;
        }

        // Cheap suffix gate first to avoid allocating a lowercased copy of every
        // method id Psalm hands us. `MethodIdentifier` guarantees the method-name
        // suffix is already lowercase (vendor/vimeo/psalm/.../MethodIdentifier.php
        // declares `lowercase-string $method_name`), so a literal substring match
        // is sound and allocation-free. This handler hooks every method call in
        // the analyzed codebase; skipping `strtolower` on the >99% reject path
        // is a measurable analysis-time win on large projects.
        $methodId = $event->getMethodId();

        if (!self::isViewLikeMethodId($methodId)) {
            // Facade aliases may inherit make() rather than re-declare it, so
            // also probe the appearing id. Same suffix gate.
            $methodId = $event->getAppearingMethodId();

            if (!self::isViewLikeMethodId($methodId)) {
                return;
            }
        }

        // We deliberately check both the resolved and the appearing method ids
        // so facade aliases (which inherit `make()` rather than re-declaring
        // it) are also caught when the resolved id is a non-Factory class.
        $spec = self::$methodSpecs[\strtolower($methodId)]
            ?? self::$methodSpecs[\strtolower($event->getAppearingMethodId())]
            ?? null;

        if ($spec === null) {
            return;
        }

        $taintGraph = $event->getCodebase()->taint_flow_graph;

        if (!$taintGraph instanceof \Psalm\Internal\Codebase\TaintFlowGraph) {
            return;
        }

        $args = $event->getExpr()->getArgs();

        if (!isset($args[$spec->viewArgIndex])) {
            return;
        }

        self::dispatchSinks(
            map: self::$map,
            viewArg: $args[$spec->viewArgIndex],
            dataArg: $args[$spec->dataArgIndex] ?? null,
            mergeDataArg: $spec->mergeDataArgIndex !== null
                ? ($args[$spec->mergeDataArgIndex] ?? null)
                : null,
            source: $event->getStatementsSource(),
            codebase: $event->getCodebase(),
            taintGraph: $taintGraph,
        );
    }

    /**
     * Walk both data arguments and install the right sinks for the resolved
     * safety record. Extracted so the function and method entry points share
     * the same logic.
     */
    private static function dispatchSinks(
        BladeSafetyMap $map,
        Arg $viewArg,
        ?Arg $dataArg,
        ?Arg $mergeDataArg,
        StatementsSource $source,
        Codebase $codebase,
        TaintFlowGraph $taintGraph,
    ): void {
        // No data array means nothing to sink. `view('home')` with zero
        // arguments is the most common shape and short-circuits cleanly here.
        if (!$dataArg instanceof \PhpParser\Node\Arg && !$mergeDataArg instanceof \PhpParser\Node\Arg) {
            return;
        }

        $viewName = self::literalString($viewArg);

        if ($viewName === null) {
            // Dynamic view name: handler cannot resolve which template will
            // render, so apply the conservative whole-data fallback. This is
            // the documented `BladeUncertaintyReason::DynamicViewName` policy.
            self::installWholeDataSink('<dynamic>', $dataArg, $source, $codebase, $taintGraph);
            self::installWholeDataSink('<dynamic>', $mergeDataArg, $source, $codebase, $taintGraph);

            return;
        }

        $safety = $map->safetyFor($viewName);

        if (!$safety instanceof \Psalm\LaravelPlugin\Blade\BladeViewSafety) {
            // View not in the map. Could be a typo (MissingViewHandler already
            // reports that), a namespaced view from a package the scanner did
            // not see, or a view added after the map was built. We deliberately
            // do NOT fall back to the whole-data sink here: it would either
            // duplicate the missing-view diagnostic with a less-actionable
            // signal, or introduce false positives on package views the user
            // cannot influence.
            return;
        }

        match ($safety->kind()) {
            BladeViewSafetyKind::Safe => null,
            BladeViewSafetyKind::UnsafeKeys => self::installUnsafeKeySinks(
                $viewName,
                $safety,
                $dataArg,
                $mergeDataArg,
                $source,
                $codebase,
                $taintGraph,
            ),
            BladeViewSafetyKind::Unknown => self::installUnknownFallback(
                $viewName,
                $dataArg,
                $mergeDataArg,
                $source,
                $codebase,
                $taintGraph,
            ),
        };
    }

    private static function installUnsafeKeySinks(
        string $viewName,
        BladeViewSafety $safety,
        ?Arg $dataArg,
        ?Arg $mergeDataArg,
        StatementsSource $source,
        Codebase $codebase,
        TaintFlowGraph $taintGraph,
    ): void {
        $unsafeKeys = $safety->unsafeKeys();

        if ($unsafeKeys === []) {
            // Defensive: UnsafeKeys analysis MUST have at least one key.
            // BladeTemplateAnalysis::unsafeKeys() coerces empty lists to Safe,
            // so this branch is unreachable through the factory. Falling back
            // to whole-data on an empty unsafe-key list would be the wrong
            // signal — bail instead.
            return;
        }

        foreach ([$dataArg, $mergeDataArg] as $arg) {
            if (!$arg instanceof \PhpParser\Node\Arg) {
                continue;
            }

            self::installSinksForArg($viewName, $arg, $unsafeKeys, $source, $codebase, $taintGraph);
        }
    }

    /**
     * @param list<non-empty-string> $unsafeKeys
     */
    private static function installSinksForArg(
        string $viewName,
        Arg $arg,
        array $unsafeKeys,
        StatementsSource $source,
        Codebase $codebase,
        TaintFlowGraph $taintGraph,
    ): void {
        $arrayLiteral = self::asArrayLiteral($arg);

        if (!$arrayLiteral instanceof \PhpParser\Node\Expr\Array_) {
            // Cannot map keys without an inline array literal. Apply the
            // conservative whole-data sink so untracked keys still surface.
            self::installWholeDataSink($viewName, $arg, $source, $codebase, $taintGraph);

            return;
        }

        $unsafeKeyLookup = \array_fill_keys($unsafeKeys, true);

        foreach ($arrayLiteral->items as $item) {
            if ($item === null) {
                continue;
            }

            $key = self::literalArrayKey($item);

            if ($key === null || !isset($unsafeKeyLookup[$key])) {
                continue;
            }

            self::installSinkForExpression(
                viewName: $viewName,
                key: $key,
                expr: $item->value,
                source: $source,
                codebase: $codebase,
                taintGraph: $taintGraph,
            );
        }
    }

    private static function installUnknownFallback(
        string $viewName,
        ?Arg $dataArg,
        ?Arg $mergeDataArg,
        StatementsSource $source,
        Codebase $codebase,
        TaintFlowGraph $taintGraph,
    ): void {
        self::installWholeDataSink($viewName, $dataArg, $source, $codebase, $taintGraph);
        self::installWholeDataSink($viewName, $mergeDataArg, $source, $codebase, $taintGraph);
    }

    /**
     * Install an `html` sink on every value of the data argument, regardless
     * of key. Used for UNKNOWN templates, dynamic view names, and the
     * non-array-literal fallback in UNSAFE_KEYS handling.
     *
     * If the data argument is an inline array literal we iterate items and
     * sink each value individually, so per-item parent nodes are picked up.
     * Otherwise we attach a single sink to the whole argument's value
     * expression — Psalm's array-value flow propagates element taints through
     * that node.
     */
    private static function installWholeDataSink(
        string $viewName,
        ?Arg $arg,
        StatementsSource $source,
        Codebase $codebase,
        TaintFlowGraph $taintGraph,
    ): void {
        if (!$arg instanceof \PhpParser\Node\Arg) {
            return;
        }

        $arrayLiteral = self::asArrayLiteral($arg);

        if ($arrayLiteral instanceof \PhpParser\Node\Expr\Array_) {
            foreach ($arrayLiteral->items as $item) {
                if ($item === null) {
                    continue;
                }

                $key = self::literalArrayKey($item) ?? '<entry>';

                self::installSinkForExpression(
                    viewName: $viewName,
                    key: $key,
                    expr: $item->value,
                    source: $source,
                    codebase: $codebase,
                    taintGraph: $taintGraph,
                );
            }

            return;
        }

        // Non-literal data argument (e.g. `view('x', $data)`). Sink the value
        // expression itself; Psalm's array-arg flow plumbs element taints
        // through the argument's parent nodes.
        self::installSinkForExpression(
            viewName: $viewName,
            key: '<argument>',
            expr: $arg->value,
            source: $source,
            codebase: $codebase,
            taintGraph: $taintGraph,
        );
    }

    /**
     * Attach an `html` sink to the given expression's data-flow parent nodes.
     *
     * Sink identity has two components:
     *  - the `method_id` carries the view name and key so two keys in the same
     *    call site produce distinct node ids. Without this, both
     *    `view('post', ['bio' => $a, 'title' => $b])` keys would hash to the
     *    same `laravel-blade-view-data#1` id and the second sink would
     *    silently overwrite the first inside {@see TaintFlowGraph::$sinks}.
     *  - the {@see CodeLocation} drives the per-call specialisation, keeping
     *    sinks from two different call sites distinct even when they target
     *    the same `(view, key)` pair.
     *
     * The cased label restates the same identity in a user-readable form;
     * Psalm surfaces it as the sink description on every TaintedHtml report.
     */
    private static function installSinkForExpression(
        string $viewName,
        string $key,
        Expr $expr,
        StatementsSource $source,
        Codebase $codebase,
        TaintFlowGraph $taintGraph,
    ): void {
        $type = $source->getNodeTypeProvider()->getType($expr);

        if (!$type instanceof \Psalm\Type\Union || $type->parent_nodes === []) {
            // No data-flow nodes means no taint can reach the sink. Skip the
            // allocation rather than create a dead sink — the latter would
            // bloat the graph during whole-program analysis.
            return;
        }

        $codeLocation = new CodeLocation($source, $expr);

        // Encode (view, key) into the method id so two keys in the same call
        // produce distinct node ids. The cased id mirrors the structure for
        // human-readable diagnostics. Non-alphanumeric characters in the view
        // name (dotted form, package `::` prefix) are passed through verbatim:
        // they cannot collide with PHP identifiers, so the resulting node id
        // stays unique.
        $methodId = self::SINK_METHOD_ID . '::' . $viewName . '::' . $key;
        $casedMethodId = self::SINK_CASED_METHOD_ID . "({$viewName}, '{$key}')";

        $sink = DataFlowNode::getForMethodArgument(
            $methodId,
            $casedMethodId,
            self::SINK_ARG_OFFSET,
            $codeLocation,
            $codeLocation,
            TaintKind::INPUT_HTML,
        );

        $taintGraph->addSink($sink);

        foreach ($type->parent_nodes as $parentNode) {
            // Pass 0 for $added_taints, NOT INPUT_HTML. The 4th argument to
            // addPath is the edge's added-taints delta, not the sink's
            // matching kind. The sink's own taint kind (set via the 6th arg
            // of getForMethodArgument above) gates which kinds trigger the
            // TaintedHtml report. Adding INPUT_HTML on the edge would lift
            // unrelated taints (e.g. INPUT_SQL from a narrow custom source)
            // up to INPUT_HTML mid-flow and produce false-positive
            // TaintedHtml reports. See vendor/vimeo/psalm/src/Psalm/Internal/
            // Codebase/TaintFlowGraph.php and ArgumentAnalyzer.php for the
            // canonical pattern: added_taints is the call-site's dispatched
            // delta (usually 0), removed_taints likewise.
            $taintGraph->addPath($parentNode, $sink, 'arg');
        }
    }

    /**
     * Suffix-based fast reject for the method-call hot path. Matches the
     * method-name portion of a Psalm method id (`Class::method`) against the
     * known view-creating method names. Allocation-free; designed to run on
     * every method call analysed.
     *
     * Stays in sync with {@see buildMethodSpecs()}: every method name added
     * there must appear here. The keys table holds the actual class-bound
     * lookups; this gate just skips the lowercased-id cost for the >99% of
     * calls that miss.
     *
     * Suffixes are written in lowercase only. Psalm's
     * {@see \Psalm\Internal\MethodIdentifier} declares `lowercase-string
     * $method_name`, so the method-name part of every emitted method id is
     * already lowercased — a camelCase suffix would never match in production.
     *
     * @psalm-pure
     */
    private static function isViewLikeMethodId(string $methodId): bool
    {
        return \str_ends_with($methodId, '::make')
            || \str_ends_with($methodId, '::renderwhen')
            || \str_ends_with($methodId, '::renderunless');
    }

    /**
     * Extract a literal string view name from an argument, or null if the
     * value is dynamic. Matches {@see MissingViewHandler}'s policy: only
     * source-visible literal names are mapped.
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

    /**
     * Inline array-literal helper. Returns null for variable args, function
     * calls, or any other non-literal expression.
     *
     * Rejection cases:
     *  - argument-level spread (`view(...$args)`) — the argument itself is a
     *    spread, so the whole-arg sink fallback applies.
     *  - inline array-item spread (`view('x', ['safe' => $a, ...$rest])`) —
     *    an inner spread item contributes opaque keys we cannot enumerate.
     *    Map-driven per-key dispatch would silently miss unsafe keys hiding
     *    in `$rest`, so we conservatively fall through to the whole-arg sink.
     *
     * @psalm-mutation-free
     */
    private static function asArrayLiteral(Arg $arg): ?Array_
    {
        if ($arg->unpack) {
            return null;
        }

        if (!$arg->value instanceof Array_) {
            return null;
        }

        // Reject the array if any item carries a key shape we cannot enumerate
        // at analysis time:
        //  - spread items (`...$rest`) — runtime keys are opaque.
        //  - dynamic keys (`[$maybeKey => $value]`) — extract() binds whatever
        //    `$maybeKey` evaluates to, which could collide with an unsafe key
        //    in UNSAFE_KEYS mode and silently miss the sink.
        // Integer literal keys (`[0 => $x]`) and list-style entries (`[$x]`)
        // are NOT rejected here — they reach `literalArrayKey()` and return
        // null because extract() drops them, so they cannot bind a variable
        // name in the Blade template.
        foreach ($arg->value->items as $item) {
            if ($item === null) {
                continue;
            }

            if ($item->unpack) {
                return null;
            }

            $key = $item->key;

            if ($key === null) {
                // List-style entry; extract() drops it.
                continue;
            }

            if ($key instanceof String_) {
                // Literal string key; literalArrayKey decides identifier-ness.
                continue;
            }

            if ($key instanceof LNumber || $key instanceof Int_) {
                // Integer literal key; extract() drops it. Two class names
                // because php-parser renamed LNumber→Int_ in 5.x; we accept
                // both for forward compatibility.
                continue;
            }

            // Any other expression (Variable, FuncCall, MethodCall, etc.)
            // could resolve at runtime to a string that matches an unsafe
            // key. Bail out so the caller falls through to the conservative
            // whole-arg sink.
            return null;
        }

        return $arg->value;
    }

    /**
     * Pull the literal string key off an array item. Returns null for integer
     * keys, dynamic keys, or list-style entries (`[$foo]`) — none of those
     * correspond to a Blade data-bag key after `extract()`.
     *
     * @psalm-mutation-free
     */
    private static function literalArrayKey(ArrayItem $item): ?string
    {
        $key = $item->key;

        if (!$key instanceof \PhpParser\Node\Expr) {
            // List-style entry (`['foo', 'bar']`). Blade's `extract()` will not
            // bind these to names, so they cannot reach a raw echo by name.
            return null;
        }

        if (!$key instanceof String_) {
            // Integer keys (`[0 => $x]`) and dynamic keys are out of scope:
            // `extract()` skips non-string and non-identifier keys.
            return null;
        }

        $value = $key->value;

        if ($value === '' || \preg_match('/^[A-Za-z_]\w*$/', $value) !== 1) {
            // PHP identifiers only — `extract()` silently drops invalid names.
            return null;
        }

        return $value;
    }

    /**
     * Build the method-id table for `Factory::make()` and every facade class
     * that proxies to {@see \Illuminate\View\Factory}. The boot path passes
     * the facade list explicitly so the handler stays decoupled from
     * `FacadeMapProvider` (which is plugin-internal and harder to fake in
     * unit tests).
     *
     * @param list<class-string> $factoryFacadeClasses
     *
     * @return array<lowercase-string, MakeLikeMethodSpec>
     *
     * @psalm-pure
     */
    private static function buildMethodSpecs(array $factoryFacadeClasses): array
    {
        // `make($view, $data = [], $mergeData = [])` is the canonical shape on
        // `\Illuminate\View\Factory`. Facade classes inherit it (or stub it via
        // the plugin's generated alias stubs); the argument layout is identical.
        $makeSpec = new MakeLikeMethodSpec(
            viewArgIndex: 0,
            dataArgIndex: 1,
            mergeDataArgIndex: 2,
        );

        // `renderWhen($condition, $view, $data = [], $mergeData = [])` and
        // `renderUnless` share the layout — they delegate to make() after the
        // condition check (see Illuminate\View\Factory::renderWhen()). The
        // condition arg shifts every index by one, but the (view, data,
        // mergeData) triple still applies to taint sink dispatch.
        $renderWhenSpec = new MakeLikeMethodSpec(
            viewArgIndex: 1,
            dataArgIndex: 2,
            mergeDataArgIndex: 3,
        );

        $specs = [];

        // `Illuminate\Contracts\View\Factory` (the interface) declares only
        // `make()` from this set; `renderWhen` and `renderUnless` live on the
        // concrete class. Apps using PSR-typed constructor injection
        // (`__construct(\Illuminate\Contracts\View\Factory $views)`) emit a
        // method id resolved to the contract for `make()`, NOT the concrete
        // class — without the contract entry, that path would slip past the
        // dispatcher.
        $specs[\strtolower(\Illuminate\Contracts\View\Factory::class) . '::make'] = $makeSpec;

        // The concrete class plus every facade alias that proxies to it.
        // Facades inherit `make()` / `renderWhen()` / `renderUnless()` from
        // the underlying class via __callStatic; the plugin's generated
        // alias stubs surface them as real static methods at analysis time.
        $concreteClasses = [
            \Illuminate\View\Factory::class,
            ...$factoryFacadeClasses,
        ];

        foreach ($concreteClasses as $class) {
            $classLc = \strtolower($class);
            $specs[$classLc . '::make'] = $makeSpec;
            $specs[$classLc . '::renderwhen'] = $renderWhenSpec;
            $specs[$classLc . '::renderunless'] = $renderWhenSpec;
        }

        // Known coverage gaps left for PR-4+:
        //  - `Illuminate\View\Factory::first(array $views, $data = [], $mergeData = [])`
        //    needs a union over multiple template safety records.
        //  - `Illuminate\View\Factory::renderEach($view, $data, $iterator, $empty)`
        //    binds each item under `$iterator`, not under the data array's keys.
        //  - `Illuminate\Routing\ResponseFactory::view($view, $data, $status, $headers)`
        //    same shape as Factory::make() but on a separate class.
        //  - `Illuminate\View\View::with($key, $value)` and `::nest($key, $view, $data)`
        //    chained data binding (e.g. `view('home')->with('user', $user)`); the
        //    factory `view()` call has no `$data` arg, so the binding is invisible
        //    here.
        //  - `Illuminate\Mail\Mailable::view()/markdown()/text()` and
        //    `Illuminate\Notifications\Messages\MailMessage::view()/markdown()/text()`
        //    and `Illuminate\Mail\Mailables\Content::__construct()` — view name +
        //    data are stored on the Mailable instance and replayed through
        //    `Mailer::renderView()` inside the framework. The user's literal call
        //    site is therefore not a direct `view()` / `Factory::make()` call,
        //    and the eventual `make()` invocation reads the name from an
        //    instance property (dynamic, not literal).

        return $specs;
    }
}
