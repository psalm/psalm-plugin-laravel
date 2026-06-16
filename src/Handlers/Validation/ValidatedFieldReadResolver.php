<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Validation;

use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\Event\AddRemoveTaintsEvent;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\TaintKind;
use Psalm\Type\Union;

/**
 * Resolves "is this expression a read of a validated request field, and if so
 * which rule governs it?" — the single question {@see ValidationTaintHandler}
 * asks for both the source (addTaints) and the escape (removeTaints) sides.
 *
 * Before this class existed, the taint handler answered the question twice: a
 * keyed-method branch (`$req->input('email')`, `$safe->input('email')`) and a
 * separate magic-property branch (`$req->email`), each re-deriving the caller
 * class, the rule lookup, and the gates. Funnelling every syntax through one
 * {@see resolve} keeps the "magic property is just another spelling of a
 * validated field read" invariant in one place — the type narrowing in
 * {@see ValidatedTypeHandler} / {@see FormRequestPropertyHandler} and the taint
 * behaviour can no longer drift apart.
 *
 * The three front doors:
 *
 *   - keyed accessor method — `validated|input|string|str|array|collect('key')`
 *     on a FormRequest, on `ValidatedInput<FormRequest>`, or on a plain Request
 *     that already ran an inline `validate([...])` in the same function;
 *   - magic property fetch — `$req->email`, gated by
 *     {@see FormRequestPropertyHandler::resolveRuleForProperty} so the type and
 *     taint paths share one "does the plugin own this fetch?" decision;
 *   - inline-validate variable — a local previously bound to one of the above
 *     and cached by {@see InlineValidateRulesCollector} (issues #834 / #840).
 *
 * Whole-bag method sources (`validated()` / `validate([...])` / `safe()` with
 * no single key) resolve to a {@see ValidatedFieldRead} with only a source mask
 * and no escape — the bag is tainted input, but no single rule constrains it.
 *
 * @internal
 */
final class ValidatedFieldReadResolver
{
    /**
     * Accessor methods whose single-key form selects a rule-covered field.
     *
     * Listed explicitly (not derived) so reviewers can audit the set. Names
     * are in canonical Laravel casing; both this resolver and the sibling
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
     * Recognise the expression behind a taint event as a validated field read.
     *
     * Returns null for the overwhelming majority of expressions (the
     * `instanceof` dispatch below bails before any rule lookup). A non-null
     * result tells the caller both what to source and what to escape; the
     * caller reads whichever facet its direction needs.
     */
    public static function resolve(AddRemoveTaintsEvent $event): ?ValidatedFieldRead
    {
        $expr = $event->getExpr();

        if ($expr instanceof PropertyFetch) {
            return self::fromPropertyFetch($event, $expr);
        }

        if ($expr instanceof Variable && \is_string($expr->name)) {
            return self::fromInlineVariable($event, $expr->name);
        }

        if ($expr instanceof MethodCall && $expr->name instanceof Identifier) {
            return self::fromMethodCall($event);
        }

        return null;
    }

    /**
     * Method-call front door. A single call can be both a source (its return
     * type was narrowed, dropping the stub `@psalm-taint-source`) and an
     * escape (its literal key selects a rule). Both facets are computed here
     * so the two used to live as separate handler branches now collapse to one.
     */
    private static function fromMethodCall(AddRemoveTaintsEvent $event): ?ValidatedFieldRead
    {
        $sourceTaints = self::isValidationMethodCall($event) || self::isValidatedInputAccessor($event)
            ? TaintKind::ALL_INPUT
            : 0;

        $removedTaints = self::resolveKeyedAccessorEscape($event);

        if ($sourceTaints === 0 && $removedTaints === 0) {
            return null;
        }

        return new ValidatedFieldRead($sourceTaints, $removedTaints);
    }

    /**
     * Magic-property front door (#1016): `$req->email`, `$this->email`. The
     * shared resolver enforces the read-only / declared-property / presence
     * gates, so the type narrowing and this taint read agree on ownership.
     *
     * `Request::__get` has no stub source and the property type comes from a
     * provider (which bypasses `__get` entirely), so the read is always a
     * source as well as carrying the rule's escape mask.
     */
    private static function fromPropertyFetch(AddRemoveTaintsEvent $event, PropertyFetch $expr): ?ValidatedFieldRead
    {
        if (!FormRequestPropertyRegistrationHandler::hasAnyFormRequests()) {
            return null;
        }

        if (!$expr->name instanceof Identifier) {
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

            // Cheap exact-class check against the FormRequest registry. For a
            // non-FormRequest caller (the common case under taint analysis)
            // this short-circuits before `resolveRuleForProperty` walks storage.
            if (!FormRequestPropertyRegistrationHandler::isFormRequest($atomic->value)) {
                continue;
            }

            $rule = FormRequestPropertyHandler::resolveRuleForProperty($atomic->value, $propertyName);

            if ($rule instanceof ResolvedRule) {
                return new ValidatedFieldRead(TaintKind::ALL_INPUT, $rule->removedTaints);
            }
        }

        return null;
    }

    /**
     * Inline-validate variable front door (issues #834 / #840): a local bound
     * to a tracked accessor read on a validated Request. Escape-only — the
     * underlying accessor already sourced the value; here we just carry the
     * rule's escape across the assignment edge.
     */
    private static function fromInlineVariable(AddRemoveTaintsEvent $event, string $variableName): ?ValidatedFieldRead
    {
        // Fast bail-out for the common case where no function in the current
        // worker has populated the cache. `removeTaints` fires for every bare
        // Variable expression under taint analysis, and most projects have far
        // more variable reads than cached inline-validate bindings — so this
        // check is taken very often and cheaply avoids the lookup walk.
        if (!InlineValidateRulesCollector::hasAnyVariableBindings()) {
            return null;
        }

        $functionId = InlineValidateRulesCollector::getFunctionLikeId($event->getStatementsSource());

        if ($functionId === null) {
            return null;
        }

        $removed = InlineValidateRulesCollector::getEscapeForVariable($functionId, $variableName) ?? 0;

        if ($removed === 0) {
            return null;
        }

        return new ValidatedFieldRead(0, $removed);
    }

    /**
     * Escape mask for a keyed accessor call. The FormRequest-rules path and
     * the inline-`validate([...])` path OR their bits: a field constrained by
     * both is safe for every kind either rule escapes.
     */
    private static function resolveKeyedAccessorEscape(AddRemoveTaintsEvent $event): int
    {
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

                if ($rule instanceof ResolvedRule) {
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

            if ($rule instanceof ResolvedRule) {
                $removed |= $rule->removedTaints;
            }
        }

        return $removed;
    }

    /**
     * Common bail-out chain for every keyed accessor lookup:
     *   - expression is a MethodCall in {@see KEYED_ACCESSOR_METHODS},
     *   - a single first argument that resolves to a literal string,
     *   - no second (default) argument that could carry independent taint.
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
        $baseClass = $methodName === 'validate'
            ? \Illuminate\Http\Request::class
            : \Illuminate\Foundation\Http\FormRequest::class;

        return self::resolveCallerClass($event, $baseClass) !== null;
    }

    /**
     * Check for ValidatedInput::input(…) — any first argument, literal or not.
     *
     * The source side compensates for the type-provider override; the per-field
     * rule lookup in {@see resolveKeyedAccessorEscape} additionally requires a
     * literal key.
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
     * Resolve a class from the method call's caller type that matches or
     * extends the given base class.
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
}
