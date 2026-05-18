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
use Psalm\LaravelPlugin\Blade\BladeTemplateAnalysis;
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
 * Out of scope (tracked under issue #581 for PR-5+):
 *  - Component data flow (`<x-foo :bar="$data" />`).
 *  - Variable-bound view-builder chains (`$v = view('home'); $v->with(...)`)
 *    where the receiver-walk in {@see self::resolveReceiverViewName()} cannot
 *    recover the literal view name through the intermediate variable binding.
 *    PR-5 may attach view-name metadata to the {@see Union} returned by
 *    `view()` / `Factory::make()` so the chain resolves through the variable.
 *  - `Mailable::with($key, $value)` chained onto `Mailable::view(...)` /
 *    `Mailable::markdown(...)` / `Mailable::text(...)`. `Mailable::view`,
 *    `markdown`, `text` ARE registered as direct sink sites, but the
 *    receiver-walk does not recognise their return-`$this` chain shape, and
 *    `Mailable::with` is NOT registered as a sink site (registration without
 *    receiver-walk support would be a no-op). Users who write
 *    `(new InvoiceMail)->view('mail.invoice')->with('bio', $tainted)` will
 *    have the `view()` call analysed (no data array there) but `with()`
 *    silently miss. Same scope as variable-bound chains; same PR-5 fix.
 *  - `View::share()` global view data — runtime-injected across every
 *    subsequent `view()` call, with no per-call site for the dispatcher to
 *    hook.
 *  - Scanner result caching across analysis runs.
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
     * lower-cased method id Psalm emits in
     * {@see AfterMethodCallAnalysisEvent::getMethodId()}.
     *
     * Value is a {@see ViewBindingSinkSpec} sealed-union: the dispatcher
     * branches on the concrete spec type via `match (true)` to handle the
     * different call shapes (make/renderWhen, first, renderEach, with).
     *
     * @var array<lowercase-string, ViewBindingSinkSpec>
     */
    private static array $methodSpecs = [];

    /**
     * Lower-cased method-name suffixes that gate the hot path. Every entry
     * here must correspond to at least one method id installed by
     * {@see buildMethodSpecs()}; adding a suffix without a matching id
     * is a pure cost (lookup misses every time). Removing a suffix without
     * removing its corresponding ids silently drops the dispatch.
     *
     * Most suffixes are uncommon outside Laravel (`renderwhen`, `rendereach`,
     * `renderunless`, `markdown`, `nest`). A handful (`view`, `with`,
     * `first`, `text`, `make`, `__construct`) appear in many non-Laravel
     * call sites; the gate still rejects them cheaply via the isset check,
     * deferring the more expensive full-id lookup until after the suffix
     * matches.
     */
    private const METHOD_NAME_SUFFIXES = [
        'make' => true,
        'renderwhen' => true,
        'renderunless' => true,
        'first' => true,
        'rendereach' => true,
        'view' => true,
        'with' => true,
        'nest' => true,
        'markdown' => true,
        'text' => true,
        '__construct' => true,
    ];

    private static bool $enabled = false;

    /**
     * Initialise the handler with the safety map and the set of facade classes
     * that proxy to {@see \Illuminate\View\Factory}. Idempotent: callers may
     * invoke this multiple times during a single analysis run.
     *
     * @param list<class-string> $factoryFacadeClasses          additional class names whose
     *                                                          `::make()` (and friends) should be
     *                                                          treated as a `\Illuminate\View\Factory`
     *                                                          call site
     * @param list<class-string> $responseFactoryFacadeClasses  additional class names whose
     *                                                          `::view()` should be treated as a
     *                                                          `\Illuminate\Routing\ResponseFactory`
     *                                                          call site (e.g. `\Illuminate\Support\Facades\Response`)
     *
     * @psalm-external-mutation-free
     */
    public static function init(
        BladeSafetyMap $map,
        array $factoryFacadeClasses = [],
        array $responseFactoryFacadeClasses = [],
    ): void {
        self::$map = $map;
        self::$enabled = true;
        self::$methodSpecs = self::buildMethodSpecs($factoryFacadeClasses, $responseFactoryFacadeClasses);
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
        $source = $event->getStatementsSource();
        $codebase = $event->getCodebase();
        $map = self::$map;

        // Dispatch by concrete spec type. `match (true)` makes the dispatch
        // exhaustive — the spec union is sealed via {@see ViewBindingSinkSpec},
        // so adding a new shape requires touching this site.
        match (true) {
            $spec instanceof MakeLikeMethodSpec => self::dispatchMakeLike(
                $spec,
                $args,
                $map,
                $source,
                $codebase,
                $taintGraph,
            ),
            $spec instanceof FirstLikeMethodSpec => self::dispatchFirstLike(
                $spec,
                $args,
                $map,
                $source,
                $codebase,
                $taintGraph,
            ),
            $spec instanceof RenderEachLikeMethodSpec => self::dispatchRenderEachLike(
                $spec,
                $args,
                $map,
                $source,
                $codebase,
                $taintGraph,
            ),
            $spec instanceof WithLikeMethodSpec => self::dispatchWithLike(
                $spec,
                $args,
                $event->getExpr(),
                $map,
                $source,
                $codebase,
                $taintGraph,
            ),
        };
    }

    /**
     * Run the per-key / per-view sink installer for a {@see MakeLikeMethodSpec}
     * call. The spec can carry multiple view-name slots (Content::__construct
     * has three: view, text, markdown); each slot dispatches independently
     * against the same shared data argument.
     *
     * @param array<array-key, Arg> $args
     */
    private static function dispatchMakeLike(
        MakeLikeMethodSpec $spec,
        array $args,
        BladeSafetyMap $map,
        StatementsSource $source,
        Codebase $codebase,
        TaintFlowGraph $taintGraph,
    ): void {
        $dataArg = $args[$spec->dataArgIndex] ?? null;
        $mergeDataArg = $spec->mergeDataArgIndex !== null
            ? ($args[$spec->mergeDataArgIndex] ?? null)
            : null;

        foreach ($spec->viewArgIndices as $viewArgIndex) {
            $viewArg = $args[$viewArgIndex] ?? null;

            if (!$viewArg instanceof Arg) {
                // Missing view slot — positional argument was omitted (e.g.
                // `new Content('emails.welcome')` passing only $view).
                continue;
            }

            if (self::isLiteralNull($viewArg)) {
                // Explicit-null slot (e.g. `new Content(view: 'foo',
                // text: null, markdown: null, with: $data)`). Without this
                // check, the slot would fall through `dispatchSinks` →
                // `literalString()` (which returns null for any non-String_
                // value, including ConstFetch('null')) → the dynamic-name
                // fallback, emitting a `<dynamic>` whole-data sink for every
                // opted-out slot. The literal `null` documents intent: this
                // slot does NOT render a template; install nothing.
                continue;
            }

            self::dispatchSinks(
                map: $map,
                viewArg: $viewArg,
                dataArg: $dataArg,
                mergeDataArg: $mergeDataArg,
                source: $source,
                codebase: $codebase,
                taintGraph: $taintGraph,
            );
        }
    }

    /**
     * Dispatch sinks for `Factory::first(array $views, $data, $mergeData)`.
     * Per task spec: if every listed view is a literal string AND every
     * resolved template is SAFE or UNSAFE_KEYS, the unsafe-key sets are
     * unioned; if ANY listed template is UNKNOWN or unknown to the map, the
     * whole-data sink fires. Non-literal items in the views array, or a
     * non-array views argument, collapse to the dynamic-name fallback.
     *
     * @param array<array-key, Arg> $args
     */
    private static function dispatchFirstLike(
        FirstLikeMethodSpec $spec,
        array $args,
        BladeSafetyMap $map,
        StatementsSource $source,
        Codebase $codebase,
        TaintFlowGraph $taintGraph,
    ): void {
        $viewsArg = $args[$spec->viewsArrayArgIndex] ?? null;
        $dataArg = $args[$spec->dataArgIndex] ?? null;
        $mergeDataArg = $spec->mergeDataArgIndex !== null
            ? ($args[$spec->mergeDataArgIndex] ?? null)
            : null;

        if (!$viewsArg instanceof Arg) {
            return;
        }

        if (!$dataArg instanceof Arg && !$mergeDataArg instanceof Arg) {
            // No data to sink; nothing to do regardless of view safety.
            return;
        }

        $viewsArray = self::asArrayLiteral($viewsArg);

        if (!$viewsArray instanceof Array_) {
            // Non-array-literal views arg (`$views`, function call, etc.):
            // we cannot enumerate candidate templates. Fall back to the
            // dynamic-name policy.
            self::installWholeDataSink('<dynamic>', $dataArg, $source, $codebase, $taintGraph);
            self::installWholeDataSink('<dynamic>', $mergeDataArg, $source, $codebase, $taintGraph);

            return;
        }

        // Iterate the literal views, accumulating the unsafe-key union or
        // tripping the UNKNOWN flag the moment we see a non-literal item or
        // an unresolvable template.
        /** @var array<non-empty-string, true> $unionKeys */
        $unionKeys = [];

        $sawUnknown = false;

        $resolvedViewLabel = '';

        foreach ($viewsArray->items as $item) {
            if (!$item instanceof ArrayItem) {
                continue;
            }

            if ($item->unpack || $item->key instanceof \PhpParser\Node\Expr) {
                // Inner spread or explicit keys: we cannot enumerate the
                // list. Conservative fallback.
                $sawUnknown = true;
                break;
            }

            $value = $item->value;

            if (!$value instanceof String_) {
                // Non-literal candidate view name.
                $sawUnknown = true;
                break;
            }

            $candidateName = $value->value;

            if ($candidateName === '') {
                continue;
            }

            $safety = $map->safetyFor($candidateName);

            if (!$safety instanceof BladeViewSafety) {
                // Candidate not in the map: typo, package view, or
                // out-of-scope path. Mirrors {@see dispatchSinks()}'s
                // "view not in map → no sink" policy: do NOT promote to
                // UNKNOWN. Skipping the unresolvable candidate is sound
                // because Laravel's `first()` picks the first existing
                // view at runtime; if the in-map candidates cover the
                // existing case, their union is precise. If they don't
                // (every candidate is missing), MissingViewHandler emits
                // the typo diagnostic instead. Promoting to UNKNOWN here
                // would re-introduce the package-view false-positive
                // regression PR #684 fixed.
                continue;
            }

            $kind = $safety->kind();

            if ($kind === BladeViewSafetyKind::Unknown) {
                $sawUnknown = true;
                $resolvedViewLabel = $resolvedViewLabel === '' ? $candidateName : $resolvedViewLabel;
                break;
            }

            // SAFE templates contribute zero keys (no-op). UNSAFE_KEYS
            // contribute their keys to the union.
            foreach ($safety->unsafeKeys() as $key) {
                $unionKeys[$key] = true;
            }

            $resolvedViewLabel = $resolvedViewLabel === '' ? $candidateName : $resolvedViewLabel . '|' . $candidateName;
        }

        if ($sawUnknown) {
            // Any UNKNOWN / non-literal contributor → whole-data fallback.
            // The view label uses the first candidate that triggered the
            // fallback so the sink identity remains stable across calls.
            $label = $resolvedViewLabel === '' ? '<first-dynamic>' : $resolvedViewLabel;
            self::installWholeDataSink($label, $dataArg, $source, $codebase, $taintGraph);
            self::installWholeDataSink($label, $mergeDataArg, $source, $codebase, $taintGraph);

            return;
        }

        if ($unionKeys === []) {
            // Every literal candidate resolved SAFE. No sink needed; matches
            // the SAFE policy from PR-3 dispatch.
            return;
        }

        /** @var list<non-empty-string> $unionKeyList */
        $unionKeyList = \array_keys($unionKeys);

        $label = $resolvedViewLabel === '' ? '<first>' : $resolvedViewLabel;

        // Synthesize a safety record using the union. We pass it to the same
        // installer used for normal UNSAFE_KEYS dispatch so the sink identity
        // and per-key dispatch logic match PR-3 exactly.
        $unionSafety = new BladeViewSafety(
            $label,
            $label,
            BladeTemplateAnalysis::unsafeKeys($unionKeyList),
        );

        self::installUnsafeKeySinks(
            $label,
            $unionSafety,
            $dataArg,
            $mergeDataArg,
            $source,
            $codebase,
            $taintGraph,
        );
    }

    /**
     * Dispatch sinks for `Factory::renderEach($view, $data, $iterator,
     * $empty)`. The shape differs from `make()`: `$data` is iterable, each
     * element binds to a variable named by the literal `$iterator` in the
     * child template.
     *
     * Sink rules:
     *  - SAFE template → no sink.
     *  - UNSAFE_KEYS template + literal `$iterator` matching unsafe keys →
     *    install a sink on `$data` keyed by the iterator name.
     *  - UNSAFE_KEYS template + literal `$iterator` NOT matching → no sink.
     *  - Non-literal `$iterator` OR UNKNOWN template → whole-data sink on
     *    `$data`.
     *  - Dynamic `$view` → dynamic-name fallback (whole-data on `$data`).
     *  - View not in map → no sink (MissingViewHandler covers the typo).
     *
     * @param array<array-key, Arg> $args
     */
    private static function dispatchRenderEachLike(
        RenderEachLikeMethodSpec $spec,
        array $args,
        BladeSafetyMap $map,
        StatementsSource $source,
        Codebase $codebase,
        TaintFlowGraph $taintGraph,
    ): void {
        $viewArg = $args[$spec->viewArgIndex] ?? null;
        $dataArg = $args[$spec->dataArgIndex] ?? null;
        $iteratorArg = $args[$spec->iteratorArgIndex] ?? null;

        if (!$viewArg instanceof Arg || !$dataArg instanceof Arg) {
            return;
        }

        $viewName = self::literalString($viewArg);

        if ($viewName === null) {
            self::installWholeDataSink('<dynamic>', $dataArg, $source, $codebase, $taintGraph);

            return;
        }

        $safety = $map->safetyFor($viewName);

        if (!$safety instanceof BladeViewSafety) {
            return;
        }

        $kind = $safety->kind();

        if ($kind === BladeViewSafetyKind::Safe) {
            return;
        }

        $iteratorName = $iteratorArg instanceof Arg ? self::literalString($iteratorArg) : null;

        if ($kind === BladeViewSafetyKind::Unknown || $iteratorName === null) {
            // UNKNOWN template OR non-literal $iterator: the per-element
            // flow's destination variable is opaque. Sink the whole data
            // iterable.
            self::installWholeDataSink($viewName, $dataArg, $source, $codebase, $taintGraph);

            return;
        }

        $unsafeKeys = $safety->unsafeKeys();
        $unsafeKeyLookup = \array_fill_keys($unsafeKeys, true);

        if (!isset($unsafeKeyLookup[$iteratorName])) {
            // $iterator names a variable the child template never raw-echoes.
            // No flow possible.
            return;
        }

        // Per-element flow into an unsafe key. The data argument is an
        // iterable of element values; sinking the whole arg is the correct
        // grain because Psalm's element-level taint flow reaches the sink
        // through the iterable's parent nodes.
        self::installSinkForExpression(
            viewName: $viewName,
            key: $iteratorName,
            expr: $dataArg->value,
            source: $source,
            codebase: $codebase,
            taintGraph: $taintGraph,
        );
    }

    /**
     * Dispatch sinks for `\Illuminate\View\View::with($key, $value)`. The
     * receiver carries the view name; this method walks the receiver
     * expression to recover it.
     *
     * @param array<array-key, Arg> $args
     */
    private static function dispatchWithLike(
        WithLikeMethodSpec $spec,
        array $args,
        \PhpParser\Node\Expr\MethodCall|\PhpParser\Node\Expr\StaticCall $callExpr,
        BladeSafetyMap $map,
        StatementsSource $source,
        Codebase $codebase,
        TaintFlowGraph $taintGraph,
    ): void {
        $keyArg = $args[$spec->keyArgIndex] ?? null;
        $valueArg = $args[$spec->valueArgIndex] ?? null;

        if (!$keyArg instanceof Arg) {
            // No key argument: not a real `with` call. Nothing to sink.
            return;
        }

        // Receiver is only available on instance method calls. Static calls
        // (Facade::with(...)) do not chain off a view-builder receiver and
        // therefore cannot carry a resolvable view name; skip them.
        // `NullsafeMethodCall` cannot reach this dispatcher: Psalm rewrites
        // `?->method(...)` calls into a `VirtualMethodCall` inside a
        // VirtualTernary before `AfterMethodCallAnalysisEvent` fires (see
        // vendor/vimeo/psalm/.../NullsafeAnalyzer.php).
        if (!$callExpr instanceof \PhpParser\Node\Expr\MethodCall) {
            return;
        }

        $viewName = self::resolveReceiverViewName($callExpr->var);

        if ($viewName === null) {
            // Receiver does not name a literal view. Consistent with PR-3's
            // "view not in map → no sink" policy — silently passing the
            // whole-data fallback here would create false positives for
            // common variable-bound view-builder patterns
            // (`$v = view('home'); $v->with(...)`).
            return;
        }

        $safety = $map->safetyFor($viewName);

        if (!$safety instanceof BladeViewSafety) {
            return;
        }

        $kind = $safety->kind();

        if ($kind === BladeViewSafetyKind::Safe) {
            return;
        }

        // Laravel's `View::with($key, $value = null)` accepts `$key` as
        // string OR array. When `$key` is an array, Laravel merges every
        // entry into the view data and IGNORES `$value` entirely
        // (vendor/laravel/framework/src/Illuminate/View/View.php: `with`
        // dispatches via `is_array($key) ? $this->data = array_merge(...) :
        // $this->data[$key] = $value`). The dispatcher mirrors this:
        //
        //  - $valueArg missing (1-arg call `with([...])`): unambiguous array
        //    form per Laravel's signature default.
        //  - $keyArg->value is an inline `Array_`: always array form
        //    regardless of $value, because Laravel ignores $value when $key
        //    is an array. Covers `with([...], null)` AND `with([...], $x)`.
        //  - else (`with('key', $value)`, `with($var, $value)`,
        //    `with('key', null)`, `with($var, null)`): string-key form.
        //    No silent over-sink on the key expression.
        $keyIsLiteralArray = $keyArg->value instanceof Array_;
        $treatAsArrayForm = $valueArg === null || $keyIsLiteralArray;

        if ($treatAsArrayForm) {
            if ($kind === BladeViewSafetyKind::Unknown) {
                self::installWholeDataSink($viewName, $keyArg, $source, $codebase, $taintGraph);

                return;
            }

            // UNSAFE_KEYS template + array-form $key: dispatch the inline
            // array's entries through the same per-key path used for
            // `Factory::make($view, $data)`. When $key is a variable
            // (`with($arrayVar)`), `installSinksForArg` falls back to the
            // whole-arg sink via `asArrayLiteral` returning null — same as
            // the non-literal data argument path on `make()`.
            self::installSinksForArg($viewName, $keyArg, $safety->unsafeKeys(), $source, $codebase, $taintGraph);

            return;
        }

        // String-key form: `with($key, $value)` with non-array $key. The
        // array-form branch above already returned for $valueArg === null
        // (1-arg call) or for any inline-array $key (Laravel ignores $value
        // when $key is an array). Anything reaching this line therefore has
        // a non-null $valueArg with a non-array $key — assert to narrow
        // Psalm's null tracking.
        if (!$valueArg instanceof Arg) {
            return;
        }

        $keyName = self::literalString($keyArg);

        if ($kind === BladeViewSafetyKind::Unknown || $keyName === null) {
            // Unknown template OR non-literal key: cannot prove the value
            // does NOT flow to a raw echo. Sink the value expression itself.
            self::installSinkForExpression(
                viewName: $viewName,
                key: $keyName ?? '<argument>',
                expr: $valueArg->value,
                source: $source,
                codebase: $codebase,
                taintGraph: $taintGraph,
            );

            return;
        }

        $unsafeKeys = $safety->unsafeKeys();
        $unsafeKeyLookup = \array_fill_keys($unsafeKeys, true);

        if (!isset($unsafeKeyLookup[$keyName])) {
            // Literal key not in the unsafe set — no flow.
            return;
        }

        self::installSinkForExpression(
            viewName: $viewName,
            key: $keyName,
            expr: $valueArg->value,
            source: $source,
            codebase: $codebase,
            taintGraph: $taintGraph,
        );
    }

    /**
     * True when an argument is an explicit literal `null` constant (e.g.
     * `with('key', null)` or `new Content(view: null, ...)`). Distinguished
     * from "argument absent altogether" so callers can treat both as "no
     * value supplied" — both shapes hit the same fallback paths in the
     * View::with array form and the Content __construct dispatch.
     */
    private static function isLiteralNull(Arg $arg): bool
    {
        $value = $arg->value;

        if (!$value instanceof \PhpParser\Node\Expr\ConstFetch) {
            return false;
        }

        return $value->name->toLowerString() === 'null';
    }

    /**
     * Walk a `View::with()` receiver expression to recover the bound view
     * name. Returns null when the receiver shape cannot be resolved (variable
     * receiver, dynamic view name, chained call with no statically known
     * source).
     *
     * Resolvable shapes:
     *  - `view('home')->with(...)`
     *    – `FuncCall(name='view', args=[String('home'), ...])`
     *  - `View::make('home')->with(...)` or `app('view')->make('home')->with(...)`
     *    – `MethodCall(name='make', args=[String('home'), ...])` /
     *      `StaticCall(name='make', args=[String('home'), ...])`
     *  - `view('home')->with('a', 1)->with(...)` — recurse into receiver's
     *    receiver for any chain of `with()` calls.
     *  - `View::first(['a', 'b'])->with(...)` — first literal in the array.
     *
     * PR-4 does not propagate view names through variable bindings; a future
     * PR may attach metadata to {@see \Psalm\Type\Union} via NodeTypeProvider
     * to cover the `$v = view('home'); $v->with(...)` pattern.
     */
    private static function resolveReceiverViewName(\PhpParser\Node\Expr $receiver): ?string
    {
        // Treat nullsafe and regular method calls uniformly. We re-dispatch
        // by drilling into the same shape rather than constructing a fresh
        // MethodCall node (which would trigger Psalm's purity guards on the
        // node constructor).
        if ($receiver instanceof \PhpParser\Node\Expr\NullsafeMethodCall) {
            return self::resolveMethodCallReceiver($receiver->var, $receiver->name, $receiver->args);
        }

        if ($receiver instanceof \PhpParser\Node\Expr\FuncCall) {
            return self::viewNameFromFuncCall($receiver);
        }

        if ($receiver instanceof \PhpParser\Node\Expr\MethodCall) {
            return self::resolveMethodCallReceiver($receiver->var, $receiver->name, $receiver->args);
        }

        if ($receiver instanceof \PhpParser\Node\Expr\StaticCall) {
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
     * @param array<array-key, \PhpParser\Node\VariadicPlaceholder|\PhpParser\Node\Arg> $args
     */
    private static function resolveMethodCallReceiver(
        \PhpParser\Node\Expr $methodReceiver,
        \PhpParser\Node\Identifier|\PhpParser\Node\Expr $methodName,
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
            return self::resolveReceiverViewName($methodReceiver);
        }

        if ($methodNameLc === 'make') {
            return self::viewNameFromArg($args[0] ?? null);
        }

        if ($methodNameLc === 'first') {
            return self::firstLiteralFromArrayArg($args[0] ?? null);
        }

        return null;
    }

    private static function viewNameFromFuncCall(\PhpParser\Node\Expr\FuncCall $call): ?string
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
    private static function viewNameFromArg(\PhpParser\Node\VariadicPlaceholder|\PhpParser\Node\Arg|null $arg): ?string
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
     * candidate arrays. `dispatchFirstLike` already takes the union across
     * all literals at the direct `Factory::first(...)` call site; the
     * receiver-walk path (chained `with()` off a `first()` receiver) does
     * not have an equivalent union mechanism wired here yet. Treating the
     * receiver as unresolvable is consistent with PR-3's "view not in map
     * → no sink" policy.
     *
     * @psalm-mutation-free
     */
    private static function firstLiteralFromArrayArg(\PhpParser\Node\VariadicPlaceholder|\PhpParser\Node\Arg|null $arg): ?string
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
     * Suffix-based fast reject for the method-call hot path. Extracts the
     * method-name portion of a Psalm method id (`Class::method`) and probes
     * it against {@see self::METHOD_NAME_SUFFIXES}. Designed to run on every
     * method call analysed; one `strrpos` + one `substr` + one isset.
     *
     * Stays in sync with {@see buildMethodSpecs()}: every method name added
     * there must appear in the suffix table. The keys table holds the actual
     * class-bound lookups; this gate just rejects the >99% of calls that miss
     * before the dispatcher allocates a lower-cased copy of the full id.
     *
     * Psalm's {@see \Psalm\Internal\MethodIdentifier} declares the method-name
     * suffix as `lowercase-string`, so the substring extracted here is
     * already lower-case — a camelCase entry in the suffix table would never
     * match in production.
     *
     * @psalm-pure
     */
    private static function isViewLikeMethodId(string $methodId): bool
    {
        $pos = \strrpos($methodId, '::');

        if ($pos === false) {
            return false;
        }

        return isset(self::METHOD_NAME_SUFFIXES[\substr($methodId, $pos + 2)]);
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
     * Build the method-id table for every Laravel API the handler dispatches
     * on. The boot path passes the facade list explicitly so the handler
     * stays decoupled from `FacadeMapProvider` (which is plugin-internal and
     * harder to fake in unit tests).
     *
     * @param list<class-string> $factoryFacadeClasses
     * @param list<class-string> $responseFactoryFacadeClasses
     *
     * @return array<lowercase-string, ViewBindingSinkSpec>
     *
     * @psalm-pure
     */
    private static function buildMethodSpecs(
        array $factoryFacadeClasses,
        array $responseFactoryFacadeClasses = [],
    ): array {
        // `make($view, $data = [], $mergeData = [])` is the canonical shape on
        // `\Illuminate\View\Factory`. Facade classes inherit it (or stub it via
        // the plugin's generated alias stubs); the argument layout is identical.
        $makeSpec = new MakeLikeMethodSpec(
            viewArgIndices: [0],
            dataArgIndex: 1,
            mergeDataArgIndex: 2,
        );

        // `renderWhen($condition, $view, $data = [], $mergeData = [])` and
        // `renderUnless` share the layout — they delegate to make() after the
        // condition check (see Illuminate\View\Factory::renderWhen()). The
        // condition arg shifts every index by one, but the (view, data,
        // mergeData) triple still applies to taint sink dispatch.
        $renderWhenSpec = new MakeLikeMethodSpec(
            viewArgIndices: [1],
            dataArgIndex: 2,
            mergeDataArgIndex: 3,
        );

        // `first(array $views, $data = [], $mergeData = [])`. Multi-template
        // union; see {@see FirstLikeMethodSpec}.
        $firstSpec = new FirstLikeMethodSpec(
            viewsArrayArgIndex: 0,
            dataArgIndex: 1,
            mergeDataArgIndex: 2,
        );

        // `renderEach($view, $data, $iterator, $empty = 'raw|')`.
        $renderEachSpec = new RenderEachLikeMethodSpec(
            viewArgIndex: 0,
            dataArgIndex: 1,
            iteratorArgIndex: 2,
        );

        // `ResponseFactory::view($view, $data, $status, $headers)`. Same
        // (view, data) layout as `make()` but with no mergeData; the third
        // arg is the response status and is not a view-data carrier.
        $responseViewSpec = new MakeLikeMethodSpec(
            viewArgIndices: [0],
            dataArgIndex: 1,
            mergeDataArgIndex: null,
        );

        // `View::with($key, $value)`. Receiver-resolved single key/value
        // sink; see {@see WithLikeMethodSpec}.
        $withSpec = new WithLikeMethodSpec(
            keyArgIndex: 0,
            valueArgIndex: 1,
        );

        // `View::nest($key, $view, $data = [])`. Equivalent to a child
        // Factory::make() call: view at index 1, data at index 2, no
        // mergeData. The receiver's view name is irrelevant (the nested
        // view's safety record governs the sink).
        $nestSpec = new MakeLikeMethodSpec(
            viewArgIndices: [1],
            dataArgIndex: 2,
            mergeDataArgIndex: null,
        );

        // `Mailable::view($view, array $data = [])` and the markdown / text
        // siblings; `MailMessage` has the same shape on its three methods.
        // None of these have a separate mergeData slot — the second argument
        // is the single data array.
        $mailMethodSpec = new MakeLikeMethodSpec(
            viewArgIndices: [0],
            dataArgIndex: 1,
            mergeDataArgIndex: null,
        );

        // `Content::__construct(?$view, ?$html, ?$text, $markdown, $with,
        // ?$htmlString)`. Three view-name slots (view at 0, text at 2,
        // markdown at 3) share the single `$with` data array at index 4.
        // `$html` (index 1) and `$htmlString` (index 5) are pre-rendered
        // HTML strings, not Blade view names; they are not registered. The
        // dispatcher emits one sink per view slot that resolves to a
        // literal string at the call site.
        $contentConstructorSpec = new MakeLikeMethodSpec(
            viewArgIndices: [0, 2, 3],
            dataArgIndex: 4,
            mergeDataArgIndex: null,
        );

        $specs = [];

        // `Illuminate\Contracts\View\Factory` (the interface) declares only
        // `make()` from this set; `renderWhen`, `renderUnless`, `first`,
        // `renderEach` live on the concrete class. Apps using PSR-typed
        // constructor injection emit method ids resolved to the contract
        // for `make()`; without the contract entry, that path would slip
        // past the dispatcher.
        $specs[\strtolower(\Illuminate\Contracts\View\Factory::class) . '::make'] = $makeSpec;

        // `Illuminate\Contracts\View\View` declares `with()` (but not
        // `nest()`). Mirror the same routing as the concrete class.
        $viewContractLc = \strtolower(\Illuminate\Contracts\View\View::class);
        $specs[$viewContractLc . '::with'] = $withSpec;

        // The concrete View\Factory plus every facade alias that proxies to
        // it. Facades inherit make/renderWhen/renderUnless/first/renderEach
        // from the underlying class via __callStatic; the plugin's generated
        // alias stubs surface them as real static methods at analysis time.
        $factoryConcrete = [
            \Illuminate\View\Factory::class,
            ...$factoryFacadeClasses,
        ];

        foreach ($factoryConcrete as $class) {
            $classLc = \strtolower($class);
            $specs[$classLc . '::make'] = $makeSpec;
            $specs[$classLc . '::renderwhen'] = $renderWhenSpec;
            $specs[$classLc . '::renderunless'] = $renderWhenSpec;
            $specs[$classLc . '::first'] = $firstSpec;
            $specs[$classLc . '::rendereach'] = $renderEachSpec;
        }

        // `Illuminate\View\View` carries the chained `with()` and `nest()`.
        $viewClassLc = \strtolower(\Illuminate\View\View::class);
        $specs[$viewClassLc . '::with'] = $withSpec;
        $specs[$viewClassLc . '::nest'] = $nestSpec;

        // `Illuminate\Routing\ResponseFactory::view()` and its contract,
        // plus every facade class that proxies to either binding (e.g.
        // `\Illuminate\Support\Facades\Response`). Without the facade list,
        // calls written as `\Response::view(...)` would slip past the
        // dispatcher entirely — Psalm resolves the method id to the facade
        // class, not to the contract.
        $responseFactoryClasses = [
            \Illuminate\Routing\ResponseFactory::class,
            \Illuminate\Contracts\Routing\ResponseFactory::class,
            ...$responseFactoryFacadeClasses,
        ];

        foreach ($responseFactoryClasses as $class) {
            $specs[\strtolower($class) . '::view'] = $responseViewSpec;
        }

        // Mail / Notification view-binding methods: `view`, `markdown`, `text`
        // on Mailable and MailMessage. Identical layout; one spec instance
        // serves both.
        $mailClasses = [
            \Illuminate\Mail\Mailable::class,
            \Illuminate\Notifications\Messages\MailMessage::class,
        ];

        foreach ($mailClasses as $class) {
            $classLc = \strtolower($class);
            $specs[$classLc . '::view'] = $mailMethodSpec;
            $specs[$classLc . '::markdown'] = $mailMethodSpec;
            $specs[$classLc . '::text'] = $mailMethodSpec;
        }

        // `Mail\Mailables\Content::__construct` — three view slots, one data
        // slot, dispatched as a single multi-view spec.
        $specs[\strtolower(\Illuminate\Mail\Mailables\Content::class) . '::__construct'] = $contentConstructorSpec;

        // Known coverage gaps left for PR-5+:
        //  - Component data flow `<x-foo :bar="$data" />` (PR-5 scope).
        //  - `View::share()` global view data — the binding is replayed at
        //    render-time across every subsequent `view()` call from any
        //    caller, so the scanner's per-call dispatch model does not fit.
        //  - Variable-bound view-builder chains
        //    (`$v = view('home'); $v->with(...)`) — PR-5 may attach view
        //    names to {@see \Psalm\Type\Union} via NodeTypeProvider metadata
        //    to recover the view name through indirections.
        //  - Caching the safety map across analysis runs — handled in a
        //    follow-up after PR-5 stabilises the map shape.

        return $specs;
    }
}
