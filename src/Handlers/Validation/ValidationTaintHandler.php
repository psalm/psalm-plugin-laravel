<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Validation;

use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use Psalm\Internal\Analyzer\FunctionLikeAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\AddTaintsInterface;
use Psalm\Plugin\EventHandler\AfterFileAnalysisInterface;
use Psalm\Plugin\EventHandler\AfterFunctionLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\BeforeExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AddRemoveTaintsEvent;
use Psalm\Plugin\EventHandler\Event\AfterFileAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\AfterFunctionLikeAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\BeforeExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\RemoveTaintsInterface;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\TaintKind;
use Psalm\Type\Union;

/**
 * Manages taint for validated data read from FormRequest, Request, and ValidatedInput.
 *
 * Two responsibilities, split across the two interfaces:
 *
 * 1. Add taint where {@see ValidatedTypeHandler} narrows the return type.
 *    A type-provider override causes Psalm to drop the stub's
 *    @psalm-taint-source annotation, so we must re-introduce it.
 *    Covers: FormRequest::validated()/safe()/validate(),
 *            ValidatedInput::input('key').
 *
 * 2. Remove taint per field when the declared validation rule constrains the
 *    value in a way that makes it safe for a specific sink family
 *    (e.g. 'email' rule → safe for 'header' and 'cookie').
 *    Covers keyed accessors that read from the same data pool as validation:
 *            FormRequest::validated/input/string/str/array/collect('key'),
 *            ValidatedInput::input/string/str/collect('key'),
 *            Request::input/string/str/array/collect('key') after an in-controller
 *            `$request->validate([...])` in the same function — rules come
 *            from {@see InlineValidateRulesCollector}.
 *            (The `array`/`collect` accessors are bulk-input forms that read
 *            the same pool as `input()`; see issue #840. The shared
 *            `KEYED_ACCESSOR_METHODS` list also matches `ValidatedInput::array`
 *            in principle, but the current `ValidatedInput` stub omits
 *            `array()` so Psalm never sees that call shape — stub gap, not
 *            handler gap.)
 *
 * 3. Add and remove taint for FormRequest magic property reads — `$this->email`,
 *    `$req->email` — paired with the type narrowing in
 *    {@see FormRequestPropertyHandler} (#1016). Unlike the method paths, there
 *    is no stub @psalm-taint-source on `__get`, so the addTaints branch is
 *    purely additive (without it, a sink consuming `$this->email` would never
 *    see the input taint).
 *
 * Design assumption: when a typed FormRequest is injected into a controller,
 * Laravel runs validation before the controller method executes (via
 * ValidatesWhenResolvedTrait). So any keyed accessor read from that
 * FormRequest carries a value that already passed rules() — the rule's taint
 * escape applies even when the caller uses input() instead of validated().
 *
 * Caveat: the escape on the keyed accessors assumes validation has run
 * against the same data pool these accessors read. That assumption can break
 * in a few (rare) scenarios:
 *   - a subclass's passedValidation() calls $this->merge(...) with raw content
 *     on a rule-covered key;
 *   - a subclass overrides validationData() to validate a different source
 *     (e.g. $this->json()->all()) than input() reads;
 *   - input() is called before validation runs (e.g. inside prepareForValidation,
 *     rules(), or authorize()) — the static analyzer cannot see call ordering;
 *   - precognition mode strips rules from the live validator while the static
 *     rules() still parses the full set.
 * In all of these, validated() and safe()->input() still reflect the validated
 * snapshot. Prefer them in security-sensitive paths.
 *
 * NOT handled here (deliberate):
 *   - query(), post(), json(), cookie(), server(), header(), file():
 *     these read from a specific transport rather than the validated merge,
 *     so a rule on 'team_email' does not necessarily describe $req->query('team_email').
 *   - integer/float/boolean/date/enum:
 *     cast methods are not taint sources (see InteractsWithData.phpstub).
 *
 * Upstream workaround for Psalm dropping the stub source on override:
 *   https://github.com/vimeo/psalm/issues/11765
 *
 * Architecture follows {@see \Psalm\Internal\Provider\AddRemoveTaints\HtmlFunctionTainter}.
 *
 * Performance: fires on every expression. The bail-out chain rejects non-matching
 * expressions fast (instanceof, then method name, then caller-class check).
 */
final class ValidationTaintHandler implements
    AddTaintsInterface,
    RemoveTaintsInterface,
    BeforeExpressionAnalysisInterface,
    AfterFunctionLikeAnalysisInterface,
    AfterFileAnalysisInterface
{
    /**
     * Object IDs of PropertyFetch nodes appearing on the LHS of an
     * assignment (`$req->email = $foo`). Populated by
     * {@see beforeExpressionAnalysis} when an `Assign` / `AssignOp` /
     * `AssignRef` expression with a `PropertyFetch` target is about to be
     * analyzed, consulted by {@see resolvePropertyFetchRule} so the
     * property-write dispatch in
     * `\Psalm\Internal\Analyzer\Statements\Expression\Assignment\InstancePropertyAssignmentAnalyzer::analyzePropertyAssignment`
     * (lines 523, 618) is skipped — that dispatch passes the LHS PropertyFetch
     * as `AddRemoveTaintsEvent::getExpr()`, and applying the rule's escape
     * mask there would strip taint from the value being written instead of
     * the value being read.
     *
     * Cleared per function-like in {@see afterStatementAnalysis} to bound
     * the memory footprint over a long-running analyzer run.
     *
     * @var array<int, true>
     */
    private static array $assignmentLhsPropertyFetchIds = [];

    /**
     * Object IDs of PropertyFetch nodes for which {@see addTaints} already
     * emitted a taint source. Psalm dispatches `AddRemoveTaintsEvent` for
     * the same expression from TWO sites when a property fetch is passed
     * as a function-call argument:
     *
     *   1. `AtomicPropertyFetchAnalyzer::processTaints` (line 524) — the
     *      property-read analysis pass.
     *   2. `ArgumentAnalyzer::processTaintedness` (line 971 → 1761) — the
     *      callee argument-binding pass.
     *
     * Both pass the same `PropertyFetch` node as `$event->getExpr()`. Without
     * de-duplication, every `$req->email` reaching a sink (`echo`, `header`,
     * `system`, …) ends up with TWO taint sources, producing 2x the expected
     * report count per sink. Method calls do not have this problem because
     * the two sites pass different expressions there (the method-call dispatch
     * uses `$var_expr`, the argument dispatch uses the `MethodCall`).
     *
     * The dedupe is keyed by `spl_object_id($expr)` and is bounded per
     * function-like analysis (see {@see afterStatementAnalysis}). It is
     * cosmetic for the type system but semantically required for taint —
     * a second `ALL_INPUT` source on the same node creates a redundant
     * flow edge that surfaces as a duplicate sink report.
     *
     * @var array<int, true>
     */
    private static array $addTaintsSourcedPropertyFetchIds = [];

    /**
     * Accessor methods whose single-key form selects a rule-covered field.
     *
     * Listed explicitly (not derived) so reviewers can audit the set. Names
     * are in canonical Laravel casing; both this handler and the sibling
     * {@see InlineValidateRulesCollector} reject non-canonical casing
     * deliberately. PHP resolves method names case-insensitively at runtime,
     * but Laravel code uses canonical camelCase without exception, and the
     * canonical-only check avoids a per-expression `strtolower()` allocation.
     *
     * `public` so {@see InlineValidateRulesCollector} can reuse the list
     * without risking drift — the variable-binding cache is the same data
     * flow with one extra hop, and both sites must stay in sync.
     *
     * @internal shared only with {@see InlineValidateRulesCollector}.
     */
    public const KEYED_ACCESSOR_METHODS = ['validated', 'input', 'string', 'str', 'array', 'collect'];

    /**
     * Add taint to validation method calls whose return type we narrow.
     *
     * Without this, the override in {@see ValidatedTypeHandler} would cause
     * Psalm to silently drop the stub's @psalm-taint-source annotation,
     * producing false negatives on sinks that consume the narrowed value.
     */
    #[\Override]
    public static function addTaints(AddRemoveTaintsEvent $event): int
    {
        if (self::isValidationMethodCall($event)) {
            return TaintKind::ALL_INPUT;
        }

        // ValidatedInput::input('key') also has its return type narrowed
        // (see ValidatedTypeHandler::resolveValidatedInputMethod), so the
        // stub source is dropped there as well.
        if (self::isValidatedInputAccessor($event)) {
            return TaintKind::ALL_INPUT;
        }

        // FormRequest magic property read narrowed by FormRequestPropertyHandler
        // (#1016). Unlike the method paths above there is no stub source to drop,
        // so this is purely additive — without it, `$this->email` reaches a sink
        // as untainted even when the rule is present.
        //
        // Per-expr dedupe: Psalm dispatches `AddRemoveTaintsEvent` for the same
        // PropertyFetch twice when the fetch is passed as a function-call
        // argument — once from `AtomicPropertyFetchAnalyzer::processTaints` and
        // once from `ArgumentAnalyzer::processTaintedness`. Returning
        // `ALL_INPUT` from both calls creates two taint sources on the same
        // graph node, surfacing as duplicate sink reports. The first dispatch
        // emits the source; subsequent dispatches for the same expr return 0.
        if (self::resolvePropertyFetchRule($event) instanceof ResolvedRule) {
            $exprId = \spl_object_id($event->getExpr());

            if (isset(self::$addTaintsSourcedPropertyFetchIds[$exprId])) {
                return 0;
            }

            self::$addTaintsSourcedPropertyFetchIds[$exprId] = true;

            return TaintKind::ALL_INPUT;
        }

        return 0;
    }

    /**
     * Remove taint kinds that the declared validation rule guarantees cannot
     * occur in the value.
     *
     * Two expression shapes are handled:
     *
     *   - Keyed accessor calls in `KEYED_ACCESSOR_METHODS` whose caller
     *     resolves to a `FormRequest` subclass, `ValidatedInput<FormRequest>`,
     *     or a plain `Request` that already passed an inline
     *     `$request->validate([...])` in the same function body.
     *   - A bare `Variable` whose binding was previously cached by
     *     {@see InlineValidateRulesCollector::beforeExpressionAnalysis} (for
     *     `$v = $request->input('k')`-style assignments, issue #834) or
     *     by `beforeStatementAnalysis` (for
     *     `foreach ($request->array('k') as $v)`-style direct foreach
     *     iteration, issue #840). These cover indirection cases where
     *     `MethodCallReturnTypeFetcher` and `AssignmentAnalyzer` /
     *     `arrayvalue-fetch` create separate edges and the escape would
     *     otherwise be lost on the variable hop.
     *
     * Within the keyed-accessor shape, the FormRequest and inline-validate
     * paths OR their escape bits: if both contribute a rule for the same
     * field, the value has been constrained by both and is safe for every
     * kind either rule escapes.
     */
    #[\Override]
    public static function removeTaints(AddRemoveTaintsEvent $event): int
    {
        $expr = $event->getExpr();

        // Variable case (issue #834): `$v = $req->input('k'); sink($v)`.
        // The cache was populated in `beforeExpressionAnalysis` so it is
        // visible to every subsequent removeTaints firing — including the
        // LHS event for the binding itself, which lets the rule's escape
        // also be applied to the assignment edge for free.
        if ($expr instanceof Variable && \is_string($expr->name)) {
            return self::lookupInlineValidateVariableEscape($event, $expr->name);
        }

        // FormRequest magic property read (#1016): `$this->email`, `$req->email`.
        // Same rule-escape semantics as the keyed accessors — the property name
        // is the rule key. The shared resolver enforces the read-only / declared-
        // property / presence-guarantee gates, so this branch needs no extra logic.
        if ($expr instanceof PropertyFetch) {
            $rule = self::resolvePropertyFetchRule($event);

            return $rule instanceof ResolvedRule ? $rule->removedTaints : 0;
        }

        $accessor = self::matchKeyedAccessor($event);

        if ($accessor === null) {
            return 0;
        }

        $removed = 0;

        // FormRequest::rules() path — covers both direct FormRequest callers
        // and ValidatedInput<FormRequest> callers.
        $formRequestClass = self::resolveFormRequestForAccessor($event, $accessor['method']);

        if ($formRequestClass !== null) {
            $rules = ValidationRuleAnalyzer::getRulesForFormRequest($formRequestClass);

            if ($rules !== null) {
                $rule = ValidationRuleAnalyzer::lookupRuleByKey($rules, $accessor['key']);

                if ($rule instanceof \Psalm\LaravelPlugin\Handlers\Validation\ResolvedRule) {
                    $removed |= $rule->removedTaints;
                }
            }
        }

        // Inline `$request->validate([...])` path — applies to any caller
        // variable typed as Illuminate\Http\Request (which includes every
        // FormRequest subclass, so a FormRequest with both `rules()` AND an
        // inline validate gets escape bits from both sources).
        $inlineRules = self::lookupInlineValidateRules($event, $accessor['expr']);

        if ($inlineRules !== null) {
            $rule = ValidationRuleAnalyzer::lookupRuleByKey($inlineRules, $accessor['key']);

            if ($rule instanceof \Psalm\LaravelPlugin\Handlers\Validation\ResolvedRule) {
                $removed |= $rule->removedTaints;
            }
        }

        return $removed;
    }

    /**
     * Look up the cached escape mask for a local variable previously bound
     * to a tracked accessor read on a validated Request (`$v =
     * $request->input('k')`). Returns 0 when no such binding is in scope.
     *
     * The binding is tracked per enclosing function-like; closures and
     * nested functions are separate scopes.
     */
    private static function lookupInlineValidateVariableEscape(AddRemoveTaintsEvent $event, string $variableName): int
    {
        // Fast bail-out for the common case where no function in the
        // current worker has populated the cache. `removeTaints` fires
        // for every bare Variable expression under taint analysis, and
        // most projects have far more variable reads than they have
        // cached inline-validate bindings — so this check is taken very
        // often and cheaply avoids the `getFunctionLikeId` walk.
        if (!InlineValidateRulesCollector::hasAnyVariableBindings()) {
            return 0;
        }

        $functionId = InlineValidateRulesCollector::getFunctionLikeId($event->getStatementsSource());

        if ($functionId === null) {
            return 0;
        }

        return InlineValidateRulesCollector::getEscapeForVariable($functionId, $variableName) ?? 0;
    }

    /**
     * Common bail-out chain for every keyed accessor lookup:
     *   - expression is a MethodCall named validated|input|string|str,
     *   - a single first argument that resolves to a literal string,
     *   - no second (default) argument that could carry independent taint.
     *
     * Returns the method name, the literal field key, and the underlying
     * MethodCall node so callers can reuse the already-validated AST.
     *
     * @return array{method: string, key: string, expr: MethodCall}|null
     */
    private static function matchKeyedAccessor(AddRemoveTaintsEvent $event): ?array
    {
        $expr = $event->getExpr();

        if (!$expr instanceof MethodCall || !$expr->name instanceof Identifier) {
            return null;
        }

        // Direct raw-name compare. See KEYED_ACCESSOR_METHODS docblock for
        // why canonical casing is required.
        $methodName = $expr->name->name;

        if (!\in_array($methodName, self::KEYED_ACCESSOR_METHODS, true)) {
            return null;
        }

        $args = $expr->getArgs();

        if ($args === []) {
            return null;
        }

        // A default argument (input('key', $default)) can carry its own taint.
        // The rule for 'key' describes the validated value, not the default,
        // so applying the rule's escape would wrongly clean taint that comes
        // from the default expression. Bail out — taint is preserved.
        if (isset($args[1])) {
            return null;
        }

        $statementsAnalyzer = $event->getStatementsSource();

        if (!$statementsAnalyzer instanceof StatementsAnalyzer) {
            return null;
        }

        $firstArgType = $statementsAnalyzer->node_data->getType($args[0]->value);

        if (!$firstArgType instanceof Union || !$firstArgType->isSingleStringLiteral()) {
            return null;
        }

        return [
            'method' => $methodName,
            'key' => $firstArgType->getSingleStringLiteral()->value,
            'expr' => $expr,
        ];
    }

    /**
     * Resolve the FormRequest class backing an accessor call, either directly
     * on the FormRequest or on a ValidatedInput<FormRequest>.
     *
     * @return class-string|null
     */
    private static function resolveFormRequestForAccessor(AddRemoveTaintsEvent $event, string $methodName): ?string
    {
        // Direct FormRequest caller: $req->validated|input|string|str('key')
        $formRequestClass = self::resolveCallerClass($event, \Illuminate\Foundation\Http\FormRequest::class);

        if ($formRequestClass !== null) {
            return $formRequestClass;
        }

        // ValidatedInput<FormRequest> caller: $req->safe()->input|string|str('key').
        // validated() does not exist on ValidatedInput, so this branch applies
        // only to input/string/str.
        if ($methodName !== 'validated') {
            return self::extractFormRequestFromValidatedInput($event);
        }

        return null;
    }

    /**
     * Look up inline-validate rules populated by
     * {@see InlineValidateRulesCollector} for this accessor call site.
     *
     * Requires a plain `$variable->method(...)` caller (so the variable name
     * is the cache lookup key) typed as or extending Illuminate\Http\Request,
     * and an enclosing function-like (so the cache scope is well-defined).
     *
     * @return array<string, ResolvedRule>|null
     */
    private static function lookupInlineValidateRules(AddRemoveTaintsEvent $event, MethodCall $expr): ?array
    {
        if (!$expr->var instanceof Variable || !\is_string($expr->var->name)) {
            return null;
        }

        // Cheap checks first. For the 99% of accessor calls in functions
        // that never ran validate(), the cache lookup returns null and we
        // skip the expensive classExtends walk entirely.
        $functionId = InlineValidateRulesCollector::getFunctionLikeId($event->getStatementsSource());

        if ($functionId === null) {
            return null;
        }

        $rules = InlineValidateRulesCollector::getRulesForVariable($functionId, $expr->var->name);

        if ($rules === null) {
            return null;
        }

        // Cache hit — now pay for the defence-in-depth type check. The
        // collector filters on populate; this second check guards against
        // an unrelated scope reusing the same variable name with an
        // entirely different type (a rare pathological case).
        if (self::resolveCallerClass($event, \Illuminate\Http\Request::class) === null) {
            return null;
        }

        return $rules;
    }

    /**
     * Whether the expression is validated()/validate()/safe() on Request/FormRequest,
     * or input() on a FormRequest subclass (which ValidatedTypeHandler also narrows
     * for `$this->input(...)` reads inside the request itself — see #1015).
     */
    private static function isValidationMethodCall(AddRemoveTaintsEvent $event): bool
    {
        $expr = $event->getExpr();

        if (!$expr instanceof MethodCall || !$expr->name instanceof Identifier) {
            return false;
        }

        // Raw-name compare. Non-canonical casing is rejected on the same
        // rationale as KEYED_ACCESSOR_METHODS.
        $methodName = $expr->name->name;

        if (!\in_array($methodName, ['validated', 'validate', 'safe', 'input'], true)) {
            return false;
        }

        // validate() lives on Request; everything else (including the
        // FormRequest-narrowed input(...) path) is gated on FormRequest so we
        // do not re-source taint on a plain Request::input(...) call where
        // ValidatedTypeHandler does not override the return type.
        $baseClass
            = $methodName === 'validate'
                ? \Illuminate\Http\Request::class
                : \Illuminate\Foundation\Http\FormRequest::class;

        return self::resolveCallerClass($event, $baseClass) !== null;
    }

    /**
     * Check for ValidatedInput::input(…) — any first argument, literal or not.
     *
     * addTaints compensates for the type-provider override; the per-field
     * rule lookup in removeTaints additionally requires a literal key.
     */
    private static function isValidatedInputAccessor(AddRemoveTaintsEvent $event): bool
    {
        $expr = $event->getExpr();

        if (!$expr instanceof MethodCall || !$expr->name instanceof Identifier) {
            return false;
        }

        if ($expr->name->name !== 'input') {
            return false;
        }

        if ($expr->getArgs() === []) {
            return false;
        }

        return self::extractFormRequestFromValidatedInput($event) !== null;
    }

    /**
     * Extract the FormRequest class from a ValidatedInput<FormRequest> caller type.
     *
     * The template parameter is populated when FormRequest::safe() returns
     * ValidatedInput<static> — so every safe() on a typed FormRequest is resolvable.
     *
     * @return class-string|null
     */
    private static function extractFormRequestFromValidatedInput(AddRemoveTaintsEvent $event): ?string
    {
        $expr = $event->getExpr();

        if (!$expr instanceof MethodCall) {
            return null;
        }

        $statementsAnalyzer = $event->getStatementsSource();

        if (!$statementsAnalyzer instanceof StatementsAnalyzer) {
            return null;
        }

        $callerType = $statementsAnalyzer->node_data->getType($expr->var);

        if (!$callerType instanceof Union) {
            return null;
        }

        $codebase = $event->getCodebase();

        foreach ($callerType->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TGenericObject) {
                continue;
            }

            if ($atomic->value !== \Illuminate\Support\ValidatedInput::class) {
                continue;
            }

            if (!isset($atomic->type_params[0])) {
                continue;
            }

            foreach ($atomic->type_params[0]->getAtomicTypes() as $paramAtomic) {
                if (!$paramAtomic instanceof TNamedObject) {
                    continue;
                }

                /** @var class-string $className */
                $className = $paramAtomic->value;

                try {
                    if (
                        $className === \Illuminate\Foundation\Http\FormRequest::class
                        || $codebase->classExtends($className, \Illuminate\Foundation\Http\FormRequest::class)
                    ) {
                        return $className;
                    }
                } catch (\Psalm\Exception\UnpopulatedClasslikeException|\InvalidArgumentException) {
                    continue;
                }
            }
        }

        return null;
    }

    /**
     * Resolve a class from the method call's caller type that matches or extends the given base class.
     *
     * Shared by addTaints (via isValidationMethodCall) and matchKeyedAccess
     * to avoid duplicating the classExtends resolution logic.
     *
     * @param class-string $baseClass
     * @return class-string|null
     */
    private static function resolveCallerClass(AddRemoveTaintsEvent $event, string $baseClass): ?string
    {
        $expr = $event->getExpr();

        if (!$expr instanceof MethodCall) {
            return null;
        }

        return ValidationCallerResolver::resolveCallerClass(
            $expr,
            $event->getStatementsSource(),
            $event->getCodebase(),
            $baseClass,
        );
    }

    /**
     * Mark the LHS PropertyFetch of an assignment so the property-write
     * taint dispatch (`InstancePropertyAssignmentAnalyzer` lines 523, 618)
     * is skipped by {@see resolvePropertyFetchRule}.
     *
     * The dispatch site uses the same `AddRemoveTaintsEvent` shape as the
     * property-READ dispatch in `AtomicPropertyFetchAnalyzer`, so without
     * a side-channel marker the handler cannot tell them apart — and
     * stripping the rule's escape from the write would silently launder
     * tainted data on `$req->email = $userInput`.
     *
     * `Assign` covers `$x = $y`, `AssignOp` covers `$x += $y` / `$x .= $y`,
     * `AssignRef` covers `$x =& $y`. PHP cannot magic-write through `__get`
     * (no `__set` on Request), so these shapes only matter when the user
     * declared `public $email` or the value lands in a dynamic property —
     * but every false-positive avoided here is one a downstream user does
     * not need to suppress.
     *
     * @inheritDoc
     *
     * @psalm-external-mutation-free
     */
    #[\Override]
    public static function beforeExpressionAnalysis(BeforeExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();

        if (!($expr instanceof Assign || $expr instanceof AssignOp || $expr instanceof AssignRef)) {
            return null;
        }

        if ($expr->var instanceof PropertyFetch) {
            self::$assignmentLhsPropertyFetchIds[\spl_object_id($expr->var)] = true;
        }

        return null;
    }

    /**
     * Drop assignment-LHS markers belonging to the function-like that just
     * finished analysis. Bounds the cache footprint over a long worker
     * lifetime — the cache holds entries only for in-flight functions.
     *
     * We do not have a per-function ID stamped on each entry, so the
     * simplest correct strategy is to flush the entire cache: subsequent
     * function analyses will re-populate. Same trade-off as
     * {@see InlineValidateRulesCollector::afterStatementAnalysis} (which
     * flushes its function-keyed cache at function-end).
     *
     * @inheritDoc
     *
     * @psalm-external-mutation-free
     */
    #[\Override]
    public static function afterStatementAnalysis(AfterFunctionLikeAnalysisEvent $event): ?bool
    {
        if ($event->getStatementsSource() instanceof FunctionLikeAnalyzer) {
            self::$assignmentLhsPropertyFetchIds = [];
            self::$addTaintsSourcedPropertyFetchIds = [];
        }

        return null;
    }

    /**
     * Backstop flush at file scope. The function-like flush misses two paths:
     *
     *   - Top-level script expressions (no enclosing function-like) accumulate
     *     markers that the per-function flush never visits.
     *   - PHP recycles `spl_object_id` values once the original object is
     *     garbage-collected. ASTs become GC-eligible per file (Psalm's
     *     `StatementsProvider` does not retain the parsed tree beyond
     *     `FileAnalyzer::analyze`), so a stale marker from file A can collide
     *     with a freshly-allocated PropertyFetch in file B — producing a
     *     silent false negative on the legitimate READ-side fetch.
     *
     * Flushing at file scope bounds the marker set to in-flight AST objects
     * and eliminates the id-reuse race entirely.
     *
     * @inheritDoc
     *
     * @psalm-external-mutation-free
     */
    #[\Override]
    public static function afterAnalyzeFile(AfterFileAnalysisEvent $event): void
    {
        self::$assignmentLhsPropertyFetchIds = [];
        self::$addTaintsSourcedPropertyFetchIds = [];
    }

    /**
     * Single resolver for both addTaints (source re-emission for the
     * type-narrowed read) and removeTaints (rule escape mask). Returns the
     * {@see ResolvedRule} iff every gate agrees that the plugin owns this
     * property fetch:
     *
     *   - The expression is a literal-name `PropertyFetch`.
     *   - It is NOT the LHS of an assignment ({@see beforeExpressionAnalysis}
     *     populates the marker set; we skip writes here).
     *   - At least one FormRequest subclass appears in the caller type's
     *     atomic union (cheap `isset` check against the set populated by
     *     {@see FormRequestPropertyRegistrationHandler}).
     *   - {@see FormRequestPropertyHandler::resolveRuleForProperty}
     *     agrees that the field is narrow-eligible (no declared property,
     *     no `@property`, rule guarantees presence). Sharing this resolver
     *     between the type narrowing and the taint paths is what makes the
     *     "do not create issues for declared fields" promise hold —
     *     duplication here is the bug that surfaced in PR-1016 round-1
     *     review.
     *
     * Returns the rule rather than the class so that the caller can read
     * `->removedTaints` directly without re-doing the lookup. Both
     * `addTaints` and `removeTaints` ask the same question on the same
     * event in succession; the per-(class, property) cache inside
     * `FormRequestPropertyHandler` absorbs that doubled call.
     */
    private static function resolvePropertyFetchRule(AddRemoveTaintsEvent $event): ?ResolvedRule
    {
        if (!FormRequestPropertyRegistrationHandler::hasAnyFormRequests()) {
            return null;
        }

        $expr = $event->getExpr();

        if (!$expr instanceof PropertyFetch || !$expr->name instanceof Identifier) {
            return null;
        }

        if (isset(self::$assignmentLhsPropertyFetchIds[\spl_object_id($expr)])) {
            return null;
        }

        $statementsAnalyzer = $event->getStatementsSource();

        if (!$statementsAnalyzer instanceof StatementsAnalyzer) {
            return null;
        }

        $callerType = $statementsAnalyzer->node_data->getType($expr->var);

        if (!$callerType instanceof Union) {
            return null;
        }

        $propertyName = $expr->name->name;

        foreach ($callerType->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject) {
                continue;
            }

            // Cheap exact-class check against the FormRequest registry.
            // For callers typed as a non-FormRequest object (the common
            // case under taint analysis), this short-circuits before the
            // `resolveRuleForProperty` call walks classlike storage.
            if (!FormRequestPropertyRegistrationHandler::isFormRequest($atomic->value)) {
                continue;
            }

            $rule = FormRequestPropertyHandler::resolveRuleForProperty($atomic->value, $propertyName);

            if ($rule instanceof ResolvedRule) {
                return $rule;
            }
        }

        return null;
    }
}
