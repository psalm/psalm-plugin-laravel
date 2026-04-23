<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Validation;

use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\FunctionLikeAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\AfterFunctionLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\AfterFunctionLikeAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Collects rule maps from `$request->validate([...])` and
 * `$request->validateWithBag(...)` calls in a controller body, so
 * {@see ValidationTaintHandler} can apply the per-field taint-escape bitmask
 * to later `$request->input('key')` reads on the same variable in the same
 * function-like scope.
 *
 * Why this exists (gap the FormRequest path doesn't cover):
 *
 *   public function store(Request $request): RedirectResponse
 *   {
 *       $request->validate([
 *           'email' => ['required', new AlphanumRule()], // class-level
 *                                                         // @psalm-taint-escape header
 *       ]);
 *       return redirect()->to($request->input('email')); // should be safe, not TaintedHeader
 *   }
 *
 * The FormRequest path ({@see ValidationRuleAnalyzer::getRulesForFormRequest()})
 * can't help here: the request type is the plain `Illuminate\Http\Request`, and
 * the rules are in an inline array expression, not a class `rules()` method.
 *
 * ## Scope and ordering
 *
 * Psalm walks a function body top-down. Each expression finishes analysis
 * (firing `AfterExpressionAnalysisEvent`) before the next statement's
 * expressions fire their `AddRemoveTaintsEvent`. That means by the time
 * `removeTaints` sees `$request->input('email')`, every prior
 * `$request->validate([...])` in the same function has already populated
 * this collector. The cache is queried lazily; no separate walk of the AST
 * is needed.
 *
 * Why `AfterExpressionAnalysisInterface` and not `AfterMethodCallAnalysis`:
 * Laravel declares `Request::validate()` / `validateWithBag()` via `@method`
 * docblock entries backed by runtime macros. Psalm treats those as
 * pseudo-methods, and `AfterMethodCallAnalysisEvent` only fires for methods
 * with a real declaring method id — pseudo-methods are skipped. The
 * expression-level event fires unconditionally, so it is the reliable hook.
 *
 * ## Cache lifecycle
 *
 * Entries are keyed by `spl_object_id()` of the enclosing FunctionLikeAnalyzer
 * (closure / method / function), then by caller variable name, then by field.
 * Eviction happens on `AfterFunctionLikeAnalysisEvent` for that same
 * function-like: once Psalm is done with the body, no later handler can still
 * need its rules. That keeps the cache bounded by the current analyzer's live
 * function-likes and sidesteps any `spl_object_id` reuse concern that would
 * otherwise appear when analyzers are garbage-collected mid-run.
 *
 * ## Soundness caveats
 *
 *   - Flow-insensitive within a function. A `validate()` inside a conditional
 *     branch is treated as if it always ran. Unlike the FormRequest path
 *     (where `ValidatesWhenResolvedTrait` guarantees validation runs before
 *     the controller method is entered), the inline form has no equivalent
 *     framework guarantee — a `validate()` gated by an `if` is only executed
 *     when the branch is taken. A downstream `input()` that runs even when
 *     the branch was skipped will still be treated as escaped. This is a
 *     deliberate trade-off: flow-sensitive modelling of arbitrary control
 *     structures is out of scope for the plugin. Prefer a typed FormRequest
 *     if you need the framework-level guarantee.
 *   - Variable identity is by source-level name. Reassigning `$request` to a
 *     different object between `validate()` and `input()` is not tracked
 *     here; Psalm core flow-tracks most taint through assignments, but this
 *     plugin's cache is name-keyed, so a reassignment that keeps the variable
 *     name will continue to apply the rule's escape to the new object. Rare
 *     in practice, but not impossible.
 *   - Closures are separate scopes. `validate()` in the outer function +
 *     `input()` inside a closure over `$request` does not propagate. We err
 *     toward keeping taint rather than silently dropping it.
 *   - `$this->validate($request, [...])` (from the `ValidatesRequests`
 *     trait on a controller) is not recognised — the caller is `$this`, not
 *     a Request. Users on that pattern should prefer `$request->validate(...)`
 *     or a typed FormRequest.
 *
 * ## Merge policy for repeated keys
 *
 * Two `validate()` calls on the same variable may mention the same field.
 * Both must pass before execution reaches later `input()` calls, so the
 * value satisfies both rule sets. The safe merge is OR-ing the
 * `removedTaints` bitmask (any rule that escapes a kind makes the value
 * safe for that kind). Type / nullable / required modifiers keep their
 * first-seen values — they're opaque to the taint query, which only reads
 * `removedTaints`.
 *
 * @internal public-static API is a cross-handler contract with
 * {@see ValidationTaintHandler}; not intended for third-party consumption.
 */
final class InlineValidateRulesCollector implements
    AfterExpressionAnalysisInterface,
    AfterFunctionLikeAnalysisInterface
{
    /**
     * Rules collected per enclosing FunctionLikeAnalyzer and caller
     * variable name.
     *
     * Outer key: `spl_object_id()` of the FunctionLikeAnalyzer whose body
     * contains the `validate()` call. Middle key: the caller variable's
     * name (without the `$`). Inner key: the field name being validated.
     *
     * @var array<int, array<string, array<string, ResolvedRule>>>
     */
    private static array $rulesByFunction = [];

    /** @inheritDoc */
    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();

        // Fast bail-out: reject non-MethodCall expressions on the first check
        // so the hot path is cheap. Fires for every expression in the project.
        if (!$expr instanceof MethodCall || !$expr->name instanceof Identifier) {
            return null;
        }

        // Canonical Laravel casing — direct string comparison avoids a
        // strtolower() allocation on every MethodCall in the project. PHP
        // method names are technically case-insensitive, but callers of
        // these Laravel macros always write them in canonical camelCase.
        $rawName = $expr->name->name;

        if ($rawName === 'validate') {
            $rulesArgIndex = 0;
        } elseif ($rawName === 'validateWithBag') {
            $rulesArgIndex = 1;
        } else {
            return null;
        }

        // Only plain `$variable->validate([...])`. Chained / property-access
        // callers (`$this->service->validate(...)`) would need a different
        // identity scheme to safely correlate with later input() calls.
        if (!$expr->var instanceof Variable || !\is_string($expr->var->name)) {
            return null;
        }

        if (!self::callerIsRequest($expr, $event->getStatementsSource(), $event->getCodebase())) {
            return null;
        }

        // array_values normalises Psalm's inferred `array<array-key, Arg>` to
        // `list<Arg>` (getArgs()'s docblock doesn't promise list-indexing).
        // array_slice at index 0 returns the list verbatim for `validate` and
        // trims the bag-name arg for `validateWithBag`.
        $rulesArgs = \array_values(\array_slice($expr->getArgs(), $rulesArgIndex));
        $rules = ValidationRuleAnalyzer::getRulesFromValidateArgs($rulesArgs);

        if ($rules === null) {
            return null;
        }

        $functionId = self::getFunctionLikeId($event->getStatementsSource());

        if ($functionId === null) {
            return null;
        }

        $variableName = $expr->var->name;
        $existing = self::$rulesByFunction[$functionId][$variableName] ?? [];

        foreach ($rules as $field => $resolvedRule) {
            if (!isset($existing[$field])) {
                $existing[$field] = $resolvedRule;

                continue;
            }

            // Same field repeated across multiple validate() calls — see the
            // class docblock for the merge rationale.
            $existing[$field] = new ResolvedRule(
                $existing[$field]->type,
                $existing[$field]->removedTaints | $resolvedRule->removedTaints,
                $existing[$field]->nullable,
                $existing[$field]->sometimes,
                $existing[$field]->required,
            );
        }

        self::$rulesByFunction[$functionId][$variableName] = $existing;

        return null;
    }

    /**
     * Evict the rule cache for a function-like once Psalm has finished its
     * body. Nothing later in the run needs the entry; clearing it keeps the
     * cache bounded and makes `spl_object_id` reuse across a long analysis
     * run a non-issue (the id can only be reused after the analyzer is
     * garbage-collected, which happens after we've already cleared the entry).
     *
     * @inheritDoc
     *
     * @psalm-external-mutation-free
     */
    #[\Override]
    public static function afterStatementAnalysis(AfterFunctionLikeAnalysisEvent $event): ?bool
    {
        // The event's statements source IS the FunctionLikeAnalyzer itself
        // (see FunctionLikeAnalyzer::analyze()), so its spl_object_id matches
        // the one used as the cache key in afterExpressionAnalysis.
        unset(self::$rulesByFunction[\spl_object_id($event->getStatementsSource())]);

        return null;
    }

    /**
     * Look up the rule map populated by prior inline `validate()` calls on
     * the named caller variable within a function-like scope.
     *
     * The scope is identified by an integer id previously obtained via
     * {@see getFunctionLikeId()}; pairing the two calls keeps this lookup
     * pure (an array read only), which lets Psalm's own analysis trust it.
     *
     * Returns `null` when no validate() call has populated the cache for
     * that scope and variable.
     *
     * @return array<string, ResolvedRule>|null
     *
     * @internal shared only with {@see ValidationTaintHandler}.
     *
     * @psalm-external-mutation-free
     */
    public static function getRulesForVariable(int $functionId, string $variableName): ?array
    {
        return self::$rulesByFunction[$functionId][$variableName] ?? null;
    }

    /**
     * Identify the enclosing function / method / closure by walking the
     * analyzer chain. Returns an opaque id used to key the rule cache.
     *
     * The return type is `?int` rather than `?string` today, but callers
     * should treat the value as an opaque scope handle — the internal key
     * representation may change.
     *
     * Returns `null` for code that is not inside a function-like (e.g. file
     * top-level statements), which are not a valid scope for the escape.
     *
     * @internal shared only with {@see ValidationTaintHandler}.
     */
    public static function getFunctionLikeId(StatementsSource $source): ?int
    {
        // The chain terminates naturally: FileAnalyzer sets $this->source = $this,
        // so the top of every source chain has getSource() === $this. Real chains
        // are shallow (StatementsAnalyzer → FunctionLikeAnalyzer is usually one
        // hop), and the self-reference check is the sole required safeguard.
        while (true) {
            if ($source instanceof FunctionLikeAnalyzer) {
                return \spl_object_id($source);
            }

            $parent = $source->getSource();

            if ($parent === $source) {
                return null;
            }

            $source = $parent;
        }
    }

    /**
     * Check whether the method call's caller resolves to a class that is
     * or extends `Illuminate\Http\Request` (covers FormRequest subclasses
     * too — they carry their own `rules()` method but may additionally
     * have an inline `$this->validate([...])` call).
     */
    private static function callerIsRequest(
        MethodCall $expr,
        StatementsSource $source,
        Codebase $codebase,
    ): bool {
        if (!$source instanceof StatementsAnalyzer) {
            return false;
        }

        $callerType = $source->node_data->getType($expr->var);

        if (!$callerType instanceof Union) {
            return false;
        }

        foreach ($callerType->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject) {
                continue;
            }

            /** @var class-string $className */
            $className = $atomic->value;

            if ($className === \Illuminate\Http\Request::class) {
                return true;
            }

            try {
                if ($codebase->classExtends($className, \Illuminate\Http\Request::class)) {
                    return true;
                }
            } catch (\Psalm\Exception\UnpopulatedClasslikeException|\InvalidArgumentException) {
                continue;
            }
        }

        return false;
    }
}
