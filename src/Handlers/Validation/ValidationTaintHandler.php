<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Validation;

use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\AddTaintsInterface;
use Psalm\Plugin\EventHandler\Event\AddRemoveTaintsEvent;
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
 *            FormRequest::validated/input/string/str('key'),
 *            ValidatedInput::input/string/str('key'),
 *            Request::input/string/str('key') after an in-controller
 *            `$request->validate([...])` in the same function — rules come
 *            from {@see InlineValidateRulesCollector}.
 *
 * Design assumption: when a typed FormRequest is injected into a controller,
 * Laravel runs validation before the controller method executes (via
 * ValidatesWhenResolvedTrait). So any input/string/str read from that
 * FormRequest carries a value that already passed rules() — the rule's taint
 * escape applies even when the caller uses input() instead of validated().
 *
 * Caveat: the escape on input()/string()/str() assumes validation has run
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
 *     cast methods are not taint sources (see InteractsWithData.stubphp).
 *
 * Upstream workaround for Psalm dropping the stub source on override:
 *   https://github.com/vimeo/psalm/issues/11765
 *
 * Architecture follows {@see \Psalm\Internal\Provider\AddRemoveTaints\HtmlFunctionTainter}.
 *
 * Performance: fires on every expression. The bail-out chain rejects non-matching
 * expressions fast (instanceof, then method name, then caller-class check).
 */
final class ValidationTaintHandler implements AddTaintsInterface, RemoveTaintsInterface
{
    /**
     * Accessor methods whose single-key form selects a rule-covered field.
     *
     * Listed explicitly (not derived) so reviewers can audit the set. Names
     * are in canonical Laravel casing; both this handler and the sibling
     * {@see InlineValidateRulesCollector} reject non-canonical casing
     * deliberately. PHP resolves method names case-insensitively at runtime,
     * but Laravel code uses canonical camelCase without exception, and the
     * canonical-only check avoids a per-expression `strtolower()` allocation.
     */
    private const KEYED_ACCESSOR_METHODS = ['validated', 'input', 'string', 'str'];

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
     *     {@see InlineValidateRulesCollector::beforeExpressionAnalysis}.
     *     This covers the one-hop case (`$v = $request->input('k');
     *     sink($v);`) where `MethodCallReturnTypeFetcher` and
     *     `AssignmentAnalyzer` create separate edges and the escape would
     *     otherwise be lost on the variable indirection (issue #834).
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

            if ($rules !== null && isset($rules[$accessor['key']])) {
                $removed |= $rules[$accessor['key']]->removedTaints;
            }
        }

        // Inline `$request->validate([...])` path — applies to any caller
        // variable typed as Illuminate\Http\Request (which includes every
        // FormRequest subclass, so a FormRequest with both `rules()` AND an
        // inline validate gets escape bits from both sources).
        $inlineRules = self::lookupInlineValidateRules($event, $accessor['expr']);

        if ($inlineRules !== null && isset($inlineRules[$accessor['key']])) {
            $removed |= $inlineRules[$accessor['key']]->removedTaints;
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
    private static function lookupInlineValidateVariableEscape(
        AddRemoveTaintsEvent $event,
        string $variableName,
    ): int {
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
    private static function resolveFormRequestForAccessor(
        AddRemoveTaintsEvent $event,
        string $methodName,
    ): ?string {
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
    private static function lookupInlineValidateRules(
        AddRemoveTaintsEvent $event,
        MethodCall $expr,
    ): ?array {
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
     * Whether the expression is validated()/validate()/safe() on Request/FormRequest.
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

        if (!\in_array($methodName, ['validated', 'validate', 'safe'], true)) {
            return false;
        }

        // validated() and safe() are FormRequest methods, validate() is on Request
        $baseClass = ($methodName === 'validated' || $methodName === 'safe')
            ? \Illuminate\Foundation\Http\FormRequest::class
            : \Illuminate\Http\Request::class;

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
                    if ($className === \Illuminate\Foundation\Http\FormRequest::class
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
    private static function resolveCallerClass(
        AddRemoveTaintsEvent $event,
        string $baseClass,
    ): ?string {
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
}
