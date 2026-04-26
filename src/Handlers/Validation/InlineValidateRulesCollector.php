<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Validation;

use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Foreach_;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\FunctionLikeAnalyzer;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\AfterFunctionLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\BeforeExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\BeforeStatementAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\AfterFunctionLikeAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\BeforeExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\BeforeStatementAnalysisEvent;
use Psalm\StatementsSource;

/**
 * Collects rule maps from `$request->validate([...])` and
 * `$request->validateWithBag(...)` calls in a controller body, so
 * {@see ValidationTaintHandler} can apply the per-field taint-escape bitmask
 * to later `$request->input('key')` reads on the same variable in the same
 * function-like scope. Also tracks local-variable bindings of those reads
 * (`$v = $request->input('key')`) so the escape survives the one-hop
 * variable indirection — see issue #834.
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
 *
 *       // Equally, after binding to a local variable:
 *       $email = $request->input('email');
 *       return redirect()->to($email);                    // also safe
 *   }
 *
 * The FormRequest path ({@see ValidationRuleAnalyzer::getRulesForFormRequest()})
 * can't help here: the request type is the plain `Illuminate\Http\Request`, and
 * the rules are in an inline array expression, not a class `rules()` method.
 *
 * ## Scope and ordering
 *
 * Psalm walks a function body top-down, statement by statement. Each statement
 * finishes analysis (with its AfterExpressionAnalysisEvents fired) before the
 * next statement's expressions fire their `AddRemoveTaintsEvent`. So by the time
 * `removeTaints` sees `$request->input('email')`, every `$request->validate([...])`
 * that lives in an *earlier statement* in the same function has already populated
 * this collector. The cache is queried lazily; no separate walk of the AST is
 * needed.
 *
 * Intra-statement caveat: the guarantee is statement-level, not expression-level.
 * If a single compound expression contains both a `validate(...)` and an
 * `input(...)` (e.g. `[$request->validate([...]), $request->input('k')]`), the
 * sub-expression evaluation order inside a statement is Psalm-internal and not
 * relied upon here; in such constructions the escape may not apply. This does
 * not affect idiomatic code where `validate()` and `input()` live on separate
 * statements.
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
 * Two caches share the same lifecycle: the rule map (`$rulesByFunction`) and
 * the per-variable escape mask (`$inputVariablesByFunction`). Entries are
 * keyed by `spl_object_id()` of the enclosing FunctionLikeAnalyzer
 * (closure / method / function); inner keys are the caller variable name
 * (rules) or the bound local variable name (input variables). Both caches
 * are evicted in one shot on `AfterFunctionLikeAnalysisEvent` for that same
 * function-like: once Psalm is done with the body, no later handler can still
 * need its entries. That keeps the cache bounded by the current analyzer's
 * live function-likes and sidesteps any `spl_object_id` reuse concern that
 * would otherwise appear when analyzers are garbage-collected mid-run.
 *
 * The variable-binding cache is updated in `beforeExpressionAnalysis` (for
 * `$v = $req->input('k')`-style assignments) and `beforeStatementAnalysis`
 * (for `foreach ($req->array('k') as $v)`-style direct foreach iteration,
 * issue #840), not `afterExpressionAnalysis`. The reason is ordering:
 * `AssignmentAnalyzer` fires the LHS `removeTaints` event for `$v` *during*
 * the assignment's own analysis (see
 * `AssignmentAnalyzer::analyzeAssignValueDataFlow`), which is before any
 * post-expression hook fires. Updating from `afterExpressionAnalysis` would
 * leave a stale cache entry visible to the in-flight LHS event for a
 * reassignment like `$v = $request->input('k'); $v = $_POST['raw'];` — the
 * second LHS event would silently apply the cached header/cookie escape to
 * the raw input source, masking a real `TaintedHeader`. Doing the
 * population (and eviction-by-default) in the relevant `before*Analysis`
 * hook ensures the cache is up-to-date before any LHS event for the new
 * binding fires.
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
 *   - Swallowed `ValidationException` — flow-insensitivity's worst case.
 *     A `validate()` wrapped in `try { ... } catch (ValidationException) {}`
 *     populates the cache even on the thrown path, so a later `input()`
 *     read sees unvalidated data but still gets the rule's escape applied.
 *     Realistic anti-pattern in defensive code; prefer not to swallow
 *     `ValidationException` at all, or use a typed FormRequest.
 *   - `$request->merge([...])` between `validate()` and `input()`. Laravel's
 *     `validate()` macro does not write the validated snapshot back into
 *     the Request's input bag; `$request->all()` / `input()` keep reading
 *     the live bag. A `merge()` call between the two therefore overwrites
 *     a rule-covered key with raw, un-revalidated data, but the collector's
 *     cache still carries the original rule's escape. Prefer the return
 *     value of `validate()` (or `$request->validated()` on a FormRequest)
 *     for security-sensitive reads that happen after a `merge()`.
 *   - `request()->validate([...])` (the `request()` helper) is not
 *     recognised — the caller is a `FuncCall`, not a `Variable`, so there
 *     is no source-level name to key the cache by. Fail-safe: taint is
 *     preserved on subsequent `request()->input('key')` reads.
 *   - Variable bindings only *populate* the cache for `$v =
 *     $request->input('key')`-style direct assignments. Pattern variants
 *     like `$v = $request->input('k') ?? 'default'` or chains other than
 *     the recognised accessor methods don't populate the cache, so the
 *     binding keeps the original taint and a `header` sink on `$v` still
 *     fires. Reassignment via `Expr\Assign` (`$v = $_POST['raw']`),
 *     `Expr\AssignRef` (`$v = &$other`), and list / array destructuring
 *     (`[$a, $v] = ...`, `list($a, $v) = ...`) all correctly *evict* a
 *     stale cache entry for the rebound name (see
 *     `beforeExpressionAnalysis`), so a subsequent reassignment to raw
 *     user input via these paths does NOT silently inherit the previous
 *     escape. Eviction does not repopulate from these paths — the new
 *     value comes from a container or reference target, not a tracked
 *     accessor call. `foreach (... as $v)` is the exception (issue #840):
 *     it evicts AND, when the iterable is a recognised keyed-accessor
 *     call on a tracked Request (`foreach ($req->array('k') as $v)`),
 *     repopulates the cache for `$v` with the rule's escape (see
 *     `beforeStatementAnalysis`). This compensates for Psalm's
 *     `arrayvalue-fetch` edge that bypasses the `removeTaints` mask
 *     applied to the call expression.
 *   - A tracked binding wrapped in a nested assignment whose outer LHS
 *     is the same variable (`$v = foo($v = $request->input('k'))`) may
 *     temporarily expose the inner population to the outer LHS event;
 *     this pattern is exotic enough that the trade-off favours simpler
 *     code, and the eviction at the *next* `Expr\Assign` to `$v`
 *     restores correctness. Prefer a typed FormRequest for security-
 *     sensitive paths if the binding pattern is non-trivial.
 *   - Variable-binding population needs a literal-string accessor key at
 *     the AST level (`$v = $request->input('k')`). A constant reference
 *     (`$v = $request->input(self::KEY)`) is not resolved, because
 *     `beforeExpressionAnalysis` runs before the RHS type inference that
 *     the sibling MethodCall path uses to unwrap constants. The inline
 *     form (`$request->input(self::KEY)` used directly in a sink call)
 *     still benefits from the rule's escape via the existing
 *     ArgumentAnalyzer dispatch; only the variable-bound form loses it
 *     here. Fail-safe: the binding keeps the original taint, so a
 *     `header` sink on `$v` still fires. A future enhancement could
 *     populate from `afterExpressionAnalysis` with resolved types for
 *     downstream use sites, at the cost of added complexity.
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
    AfterFunctionLikeAnalysisInterface,
    BeforeExpressionAnalysisInterface,
    BeforeStatementAnalysisInterface
{
    /**
     * Accessor methods on the validated `Request` whose single literal-key
     * read can be bound to a local variable while keeping the rule's
     * taint-escape attached to that variable.
     *
     * Sourced from {@see ValidationTaintHandler::KEYED_ACCESSOR_METHODS}
     * so the two handlers share a single definition for the same data
     * flow — the variable binding is the same flow with one extra hop.
     * Names are in canonical Laravel casing; non-canonical casing is
     * rejected for consistency with the sibling handler.
     */
    private const KEYED_ACCESSOR_METHODS = ValidationTaintHandler::KEYED_ACCESSOR_METHODS;

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

    /**
     * Per-variable taint-escape kinds for variables bound to a
     * rule-covered accessor read on a tracked Request.
     *
     * Outer key: `spl_object_id()` of the enclosing FunctionLikeAnalyzer.
     * Inner key: source-level variable name (without `$`). Value: the
     * `removedTaints` taint-kind list of the rule covering the field that
     * the variable was bound to.
     *
     * Populated and evicted in `beforeExpressionAnalysis` (Assign /
     * AssignRef / destructuring) and `beforeStatementAnalysis` (foreach,
     * issue #840) so the cache state is correct *before*
     * `AssignmentAnalyzer` fires the LHS `removeTaints` event for the new
     * binding — see "Cache lifecycle" below for why ordering matters.
     *
     * @var array<int, array<string, list<string>>>
     */
    private static array $inputVariablesByFunction = [];

    /**
     * Maintain the per-variable escape cache for `$v = $req->input('key')`
     * bindings. Runs before the assignment is analyzed so the cache is
     * up-to-date before `AssignmentAnalyzer` fires the LHS `removeTaints`
     * event (see the "Cache lifecycle" section in the class docblock for
     * why ordering matters).
     *
     * Default action on every plain-variable assignment is eviction. The
     * binding is re-populated only when the RHS is a recognised keyed
     * accessor on a tracked Request variable with a literal key matching
     * an already-collected rule. Anything else clears the slot.
     *
     * @inheritDoc
     */
    #[\Override]
    public static function beforeExpressionAnalysis(BeforeExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();

        // `AssignRef` (`$v = &$other`) rebinds `$v` to a reference. Psalm
        // doesn't propagate taint through reference aliases, but the LHS
        // event later fires for `$v` as a bare Variable on subsequent
        // reads at sinks — and a stale cache entry would silently strip
        // the rule's escape from the new (raw) source. Treat AssignRef
        // as eviction-only: the RHS is a reference target, not a tracked
        // accessor call, so there is no repopulation case to handle.
        if (!$expr instanceof Assign && !$expr instanceof AssignRef) {
            return null;
        }

        // Fast bail-out: if no rules have been collected anywhere AND no
        // variable bindings exist, there's nothing to populate (no RHS can
        // match) and nothing to evict. Skip the `getFunctionLikeId` walk
        // entirely. On a large codebase the vast majority of functions
        // never call `$request->validate([...])`, so both static caches
        // stay empty for entire files and this guard is usually taken.
        if (self::$rulesByFunction === [] && self::$inputVariablesByFunction === []) {
            return null;
        }

        $functionId = self::getFunctionLikeId($event->getStatementsSource());

        if ($functionId === null) {
            return null;
        }

        // AssignRef takes the eviction path only. Walk the LHS Variable;
        // anything else (`$obj->prop = &$other`, list-ref destructure, etc.)
        // doesn't bind a named local slot we'd track.
        if ($expr instanceof AssignRef) {
            if ($expr->var instanceof Variable && \is_string($expr->var->name)) {
                unset(self::$inputVariablesByFunction[$functionId][$expr->var->name]);
            }

            return null;
        }

        // List / array destructuring (`[$a, $v] = $src` or `list($a, $v) = $src`)
        // reassigns each named item. The AssignmentAnalyzer dispatches the LHS
        // `removeTaints` event for every inner Variable, so any stale cache
        // entry for the same name would strip the rule's escape from the raw
        // source on the destructured edge. Walk the item list and evict each
        // named Variable (keyed destructuring `[$key => $v] = ...` puts the
        // bound variable in the item's `value`, not its `key`). No
        // repopulation: destructuring assigns from a container, not from a
        // tracked accessor call.
        //
        // Note on `Array_`: nikic/php-parser's `fixupArrayDestructuring` rewrites
        // both `[$a, $v] = ...` (short form) and `list($a, $v) = ...` (long form)
        // to `Expr\List_`, so the `instanceof Array_` branch is defensive
        // against a future parser change rather than a currently reachable
        // shape. Keep it — the cost is one extra `instanceof` and the
        // robustness is worth it for a security-relevant code path.
        if ($expr->var instanceof List_ || $expr->var instanceof Array_) {
            foreach ($expr->var->items as $item) {
                self::evictDestructuredItem($item, $functionId);
            }

            return null;
        }

        // Only plain `$v = ...` continues from here. Chained LHS like
        // `$obj->prop = ...` doesn't create a named local binding, so there's
        // nothing to cache or evict.
        if (!$expr->var instanceof Variable || !\is_string($expr->var->name)) {
            return null;
        }

        $variableName = $expr->var->name;

        // Default action: evict. The reassignment severs the binding to any
        // previously cached escape, so the slot must be cleared even if the
        // RHS doesn't match the keyed-accessor pattern below — otherwise a
        // subsequent reassignment to raw user input would silently inherit
        // the previous escape and mask a real taint at the sink (#834).
        unset(self::$inputVariablesByFunction[$functionId][$variableName]);

        $escape = self::resolveEscapeFromAccessorRhs($expr->expr, $functionId);

        if ($escape === null) {
            return null;
        }

        self::$inputVariablesByFunction[$functionId][$variableName] = $escape;

        return null;
    }

    /**
     * `foreach ($iter as [$k =>] $v)` reassigns `$v` (and optionally `$k`)
     * without going through `ExpressionAnalyzer::analyze` for the binding,
     * so `beforeExpressionAnalysis` never fires for the loop-variable
     * assignment. `AssignmentAnalyzer` still dispatches the LHS
     * `removeTaints` event for those variables, and without an eviction
     * here a stale `$v` cache entry would strip the rule's escape from the
     * raw iterable element on the loop-variable edge — a real false
     * negative at downstream sinks.
     *
     * Also populates the loop variable's escape cache when the iterable is
     * a recognised keyed accessor (`foreach ($req->array('emails') as $e)`,
     * issue #840). Psalm's `arrayvalue-fetch` for a direct method call
     * builds a flow edge from the source declaration to the element,
     * bypassing the `removeTaints` mask applied to the call expression.
     * Caching the escape on the loop variable makes the bare-Variable
     * lookup in {@see ValidationTaintHandler::removeTaints} fire at every
     * `$e` read inside the body, which removes the kind on each outgoing
     * edge. Variable-bound iterables (`$xs = $req->array('k'); foreach
     * ($xs as $e)`) work without explicit population here: the rule's
     * `removeTaints` was already applied to the accessor call when `$xs`
     * was bound, and Psalm's own flow tracking carries that through the
     * iteration to `$e`.
     *
     * @inheritDoc
     */
    #[\Override]
    public static function beforeStatementAnalysis(BeforeStatementAnalysisEvent $event): ?bool
    {
        $stmt = $event->getStmt();

        if (!$stmt instanceof Foreach_) {
            return null;
        }

        // Fast bail-out: nothing to evict OR populate when no rules and no
        // existing variable bindings exist. Eviction needs a populated
        // variable cache; population needs a populated rules cache.
        if (self::$inputVariablesByFunction === [] && self::$rulesByFunction === []) {
            return null;
        }

        $functionId = self::getFunctionLikeId($event->getStatementsSource());

        if ($functionId === null) {
            return null;
        }

        self::evictForeachTarget($stmt->valueVar, $functionId);

        if ($stmt->keyVar instanceof \PhpParser\Node\Expr) {
            self::evictForeachTarget($stmt->keyVar, $functionId);
        }

        // After eviction, repopulate the loop variable's escape cache when
        // the iterable is a tracked keyed-accessor call. Only the value
        // variable is relevant — the foreach key is the array index, never
        // a rule-covered field. Destructuring (`as [$a, $b]`) is also out
        // of scope: `$req->array('k')` returns array<scalar, mixed> so the
        // per-element type is opaque, and there's no per-element rule to
        // distribute across the destructured slots.
        if ($stmt->valueVar instanceof Variable && \is_string($stmt->valueVar->name)) {
            $escape = self::resolveEscapeFromAccessorRhs($stmt->expr, $functionId);

            if ($escape !== null) {
                self::$inputVariablesByFunction[$functionId][$stmt->valueVar->name] = $escape;
            }
        }

        return null;
    }

    /**
     * Evict the cache entry for a foreach binding target. Handles the plain
     * `foreach (... as $v)` case (plain Variable) and the destructured
     * `foreach (... as [$a, $v])` case (List / Array). Anything else (a
     * property fetch `foreach (... as $this->x)`, or a variable variable
     * `$$name`) is left alone — those patterns can't occupy a named slot
     * in the per-variable cache.
     *
     * `@psalm-external-mutation-free` is the same self-`static` overclaim
     * disclaimed on `afterStatementAnalysis`; Psalm 7's
     * `MissingPureAnnotation` check demands it here too.
     *
     * @psalm-external-mutation-free
     */
    private static function evictForeachTarget(Expr $target, int $functionId): void
    {
        if ($target instanceof Variable && \is_string($target->name)) {
            unset(self::$inputVariablesByFunction[$functionId][$target->name]);

            return;
        }

        if ($target instanceof List_ || $target instanceof Array_) {
            foreach ($target->items as $item) {
                self::evictDestructuredItem($item, $functionId);
            }
        }
    }

    /**
     * Evict the cache entry for the variable named by a destructuring item.
     * Handles the nested case recursively (`[$a, [$b, $c]] = ...`).
     * Null items (skipped slots, `[, $v] = ...`) and non-Variable items
     * are ignored — no named slot to evict.
     *
     * `@psalm-external-mutation-free` is the same self-`static` overclaim
     * disclaimed on `afterStatementAnalysis`; Psalm 7's
     * `MissingPureAnnotation` check demands it here too.
     *
     * @psalm-external-mutation-free
     */
    private static function evictDestructuredItem(?ArrayItem $item, int $functionId): void
    {
        if (!$item instanceof \PhpParser\Node\ArrayItem) {
            return;
        }

        $value = $item->value;

        if ($value instanceof Variable && \is_string($value->name)) {
            unset(self::$inputVariablesByFunction[$functionId][$value->name]);

            return;
        }

        if ($value instanceof List_ || $value instanceof Array_) {
            foreach ($value->items as $nested) {
                self::evictDestructuredItem($nested, $functionId);
            }
        }
    }

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
                \array_values(\array_unique(\array_merge(
                    $existing[$field]->removedTaints,
                    $resolvedRule->removedTaints,
                ))),
                $existing[$field]->nullable,
                $existing[$field]->sometimes,
                $existing[$field]->required,
            );
        }

        self::$rulesByFunction[$functionId][$variableName] = $existing;

        return null;
    }

    /**
     * Evict both per-function caches once Psalm has finished the body.
     * Nothing later in the run needs the entries; clearing them keeps the
     * caches bounded and makes `spl_object_id` reuse across a long
     * analysis run a non-issue (the id can only be reused after the
     * analyzer is garbage-collected, which happens after we've already
     * cleared the entry).
     *
     * Annotation note: `@psalm-external-mutation-free` is a slight overclaim
     * per `docs/contributing/types.md` (which says the marker permits
     * $this-only mutation), because this method mutates a `self::$` static.
     * Psalm 7's `MissingPureAnnotation` check nevertheless demands it here
     * (security-analysis optimisation tied to the event being marked
     * `@psalm-external-mutation-free`), and the project policy forbids
     * new entries in `psalm-baseline.xml`, so the annotation stays with
     * this disclaimer rather than a baseline suppress.
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
        $functionId = \spl_object_id($event->getStatementsSource());
        unset(
            self::$rulesByFunction[$functionId],
            self::$inputVariablesByFunction[$functionId],
        );

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
     * Look up the cached escape mask for a local variable that was bound
     * to a tracked accessor read on a validated Request — either via
     * `$v = $req->{accessor}('key')` (see
     * {@see ValidationTaintHandler::KEYED_ACCESSOR_METHODS} for the full
     * list) or via `foreach ($req->{accessor}('key') as $v)` (issue #840).
     *
     * Returns `null` when the variable was never bound to such a read in
     * this scope, or has since been reassigned to anything else (the
     * eviction in `beforeExpressionAnalysis` / `beforeStatementAnalysis`
     * clears the slot on every fresh assignment to the same name).
     *
     * @return list<string>|null
     *
     * @internal shared only with {@see ValidationTaintHandler}.
     *
     * @psalm-external-mutation-free
     */
    public static function getEscapeForVariable(int $functionId, string $variableName): ?array
    {
        return self::$inputVariablesByFunction[$functionId][$variableName] ?? null;
    }

    /**
     * Cheap check: are there any cached variable-escape bindings at all?
     *
     * Lets {@see ValidationTaintHandler::lookupInlineValidateVariableEscape}
     * skip the `getFunctionLikeId` analyzer-chain walk for every bare
     * Variable expression in the project when no function has populated
     * the cache yet. The check is a single hash-table-emptiness test and
     * is safe to call on every `removeTaints` firing.
     *
     * @internal shared only with {@see ValidationTaintHandler}.
     *
     * @psalm-external-mutation-free
     */
    public static function hasAnyVariableBindings(): bool
    {
        return self::$inputVariablesByFunction !== [];
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
     * Inspect an expression (an `Expr\Assign` RHS or a `Stmt\Foreach_`
     * iterable) and return the rule's `removedTaints` list if it is a
     * recognised keyed-accessor call (see
     * {@see ValidationTaintHandler::KEYED_ACCESSOR_METHODS}) where the
     * caller variable already has rules cached for this scope and the
     * literal key matches one of those rule-covered fields.
     *
     * Pattern requirements (mirrors {@see ValidationTaintHandler::matchKeyedAccessor},
     * with the difference that the type-based caller check is replaced by
     * a name-based lookup against the rule cache — type inference for the
     * expression hasn't run yet at the `BeforeExpressionAnalysis` /
     * `BeforeStatementAnalysis` callsite):
     *
     *   - `MethodCall` with a recognised accessor method name
     *   - exactly one argument (a default arg can carry independent taint
     *     and would be stripped by the rule's escape, masking real taint —
     *     bail out)
     *   - first argument is a literal string at the AST level (matches the
     *     simple-string case; constants resolved by Psalm's type inference
     *     are deliberately not handled here, as type info isn't available
     *     before the expression has been analyzed)
     *   - caller is a plain `Variable` whose name has rules collected by a
     *     prior `validate()` in this same scope
     *   - the literal key matches one of those rules
     *
     * @return list<string>|null
     */
    private static function resolveEscapeFromAccessorRhs(Expr $rhs, int $functionId): ?array
    {
        if (!$rhs instanceof MethodCall || !$rhs->name instanceof Identifier) {
            return null;
        }

        if (!\in_array($rhs->name->name, self::KEYED_ACCESSOR_METHODS, true)) {
            return null;
        }

        $args = $rhs->getArgs();

        // Empty: nothing to look up. Has-second-arg: see method docblock.
        if ($args === [] || isset($args[1])) {
            return null;
        }

        if (!$rhs->var instanceof Variable || !\is_string($rhs->var->name)) {
            return null;
        }

        $callerRules = self::$rulesByFunction[$functionId][$rhs->var->name] ?? null;

        if ($callerRules === null) {
            return null;
        }

        $keyArg = $args[0]->value;

        if (!$keyArg instanceof String_) {
            return null;
        }

        // Share the wildcard-suffix fallback with the direct-call path in
        // ValidationTaintHandler so `$v = $request->input('email.0')` binds
        // the same escape as `$request->input('email.0')` on a validated
        // `'email.*'` rule (issue #838).
        $rule = ValidationRuleAnalyzer::lookupRuleByKey($callerRules, $keyArg->value);

        if (!$rule instanceof \Psalm\LaravelPlugin\Handlers\Validation\ResolvedRule) {
            return null;
        }

        return $rule->removedTaints;
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
        return ValidationCallerResolver::resolveCallerClass(
            $expr,
            $source,
            $codebase,
            \Illuminate\Http\Request::class,
        ) !== null;
    }
}
