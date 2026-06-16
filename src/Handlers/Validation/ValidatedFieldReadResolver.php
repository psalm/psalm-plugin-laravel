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
 * Resolves "is this expression a validated-field read, and which rule governs
 * it?" — the single question {@see ValidationTaintHandler} asks for both the
 * source (addTaints) and escape (removeTaints) sides, so the type narrowing and
 * taint behaviour cannot drift apart.
 *
 * Three front doors feed {@see resolve}:
 *   - keyed accessor — `validated|input|string|str|array|collect('key')` on a
 *     FormRequest, `ValidatedInput<FormRequest>`, or a Request that ran inline
 *     `validate([...])` in the same function;
 *   - magic property — `$req->email`, gated by
 *     {@see FormRequestPropertyHandler::resolveRuleForProperty};
 *   - inline-validate variable — a local bound to one of the above, cached by
 *     {@see InlineValidateRulesCollector} (#834 / #840).
 *
 * Whole-bag sources (`validated()` / `validate([...])` / `safe()` with no key)
 * resolve to source-only, no escape.
 *
 * @internal
 */
final class ValidatedFieldReadResolver
{
    /**
     * Accessor methods whose single-key form selects a rule-covered field.
     * Canonical Laravel casing only — the camelCase convention is universal in
     * practice, and skipping `strtolower()` keeps this off the per-expression
     * allocation path. `public` so {@see InlineValidateRulesCollector} shares
     * one definition (same flow, one extra hop).
     *
     * @internal shared only with {@see InlineValidateRulesCollector}.
     */
    public const KEYED_ACCESSOR_METHODS = ['validated', 'input', 'string', 'str', 'array', 'collect'];

    /**
     * Every method name relevant to a validated read — the source names
     * (validated/validate/safe/input) unioned with {@see KEYED_ACCESSOR_METHODS}.
     * Lets {@see fromMethodCall} bail on name before resolving the caller type.
     */
    private const ACCESSOR_METHODS = ['validated', 'validate', 'safe', 'input', 'string', 'str', 'array', 'collect'];

    /**
     * Recognise the taint event's expression as a validated read. Null for the
     * vast majority (the `instanceof` dispatch bails before any rule lookup).
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
            return self::fromMethodCall($event, $expr, $expr->name->name);
        }

        return null;
    }

    /**
     * Method-call front door. One call may be both a source (narrowed return
     * type dropped the stub source) and an escape (literal key selects a rule).
     * The caller class is resolved once and shared by both facets.
     */
    private static function fromMethodCall(AddRemoveTaintsEvent $event, MethodCall $expr, string $method): ?ValidatedFieldRead
    {
        if (!\in_array($method, self::ACCESSOR_METHODS, true)) {
            return null;
        }

        $formRequest = self::resolveCallerClass($event, \Illuminate\Foundation\Http\FormRequest::class);
        $validatedInput = $formRequest === null ? self::extractFormRequestFromValidatedInput($event) : null;

        $source = self::methodSourcesInput($event, $expr, $method, $formRequest, $validatedInput)
            ? TaintKind::ALL_INPUT
            : 0;
        $escape = self::methodEscape($event, $expr, $method, $formRequest, $validatedInput);

        if ($source === 0 && $escape === 0) {
            return null;
        }

        return new ValidatedFieldRead($source, $escape);
    }

    /**
     * Source side: the read carries user input whose stub source was dropped by
     * a type override. validate() lives on Request; validated/safe/input narrow
     * on a FormRequest; input() also narrows on ValidatedInput<FormRequest>.
     *
     * @param class-string|null $formRequest
     * @param class-string|null $validatedInput
     */
    private static function methodSourcesInput(
        AddRemoveTaintsEvent $event,
        MethodCall $expr,
        string $method,
        ?string $formRequest,
        ?string $validatedInput,
    ): bool {
        if ($method === 'validate') {
            return self::resolveCallerClass($event, \Illuminate\Http\Request::class) !== null;
        }

        if ($formRequest !== null && \in_array($method, ['validated', 'safe', 'input'], true)) {
            return true;
        }

        return $method === 'input' && $validatedInput !== null && $expr->getArgs() !== [];
    }

    /**
     * Escape side: a keyed accessor with a literal key. FormRequest rules() and
     * an inline validate([...]) on the same variable OR their escape bits.
     *
     * @param class-string|null $formRequest
     * @param class-string|null $validatedInput
     */
    private static function methodEscape(
        AddRemoveTaintsEvent $event,
        MethodCall $expr,
        string $method,
        ?string $formRequest,
        ?string $validatedInput,
    ): int {
        if (!\in_array($method, self::KEYED_ACCESSOR_METHODS, true)) {
            return 0;
        }

        $key = self::literalKey($event, $expr);

        if ($key === null) {
            return 0;
        }

        $removed = 0;

        // validated() does not exist on ValidatedInput, so fall back to the
        // ValidatedInput<FormRequest> class only for the other accessors.
        $accessorClass = $formRequest ?? ($method !== 'validated' ? $validatedInput : null);

        if ($accessorClass !== null) {
            $removed |= self::ruleEscape(ValidationRuleAnalyzer::getRulesForFormRequest($accessorClass), $key);
        }

        $removed |= self::ruleEscape(self::lookupInlineValidateRules($event, $expr), $key);

        return $removed;
    }

    /**
     * Escape mask of the rule keyed by `$key` in `$rules`, or 0.
     *
     * @param array<string, ResolvedRule>|null $rules
     *
     * @psalm-pure
     */
    private static function ruleEscape(?array $rules, string $key): int
    {
        if ($rules === null) {
            return 0;
        }

        $rule = ValidationRuleAnalyzer::lookupRuleByKey($rules, $key);

        return $rule instanceof ResolvedRule ? $rule->removedTaints : 0;
    }

    /**
     * Single literal-string key of a keyed accessor call, or null. A default
     * arg (input('k', $default)) bails: the default can carry its own taint
     * that the rule's escape must not strip.
     */
    private static function literalKey(AddRemoveTaintsEvent $event, MethodCall $expr): ?string
    {
        $args = $expr->getArgs();

        if ($args === [] || isset($args[1])) {
            return null;
        }

        $analyzer = $event->getStatementsSource();

        if (!$analyzer instanceof StatementsAnalyzer) {
            return null;
        }

        $type = $analyzer->node_data->getType($args[0]->value);

        if (!$type instanceof Union || !$type->isSingleStringLiteral()) {
            return null;
        }

        return $type->getSingleStringLiteral()->value;
    }

    /**
     * Magic-property front door (#1016): `$req->email`. Always a source — the
     * provider-supplied type bypasses `__get`, so no stub source fires (#11765)
     * — plus the rule's escape. Ownership gated by
     * {@see FormRequestPropertyHandler::resolveRuleForProperty}.
     */
    private static function fromPropertyFetch(AddRemoveTaintsEvent $event, PropertyFetch $expr): ?ValidatedFieldRead
    {
        if (!FormRequestPropertyHandler::hasAnyFormRequests()) {
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
            if (!FormRequestPropertyHandler::isFormRequest($atomic->value)) {
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
     * Inline-validate variable front door (#834 / #840): a local bound to a
     * tracked accessor read. Escape-only — the accessor already sourced it.
     */
    private static function fromInlineVariable(AddRemoveTaintsEvent $event, string $variableName): ?ValidatedFieldRead
    {
        // Fires for every bare Variable under taint analysis; most projects
        // have far more variable reads than cached bindings, so bail cheap.
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

        // Cache lookup first — null for accessor calls in functions that never
        // ran validate(), skipping the classExtends walk below.
        $functionId = InlineValidateRulesCollector::getFunctionLikeId($event->getStatementsSource());

        if ($functionId === null) {
            return null;
        }

        $rules = InlineValidateRulesCollector::getRulesForVariable($functionId, $expr->var->name);

        if ($rules === null) {
            return null;
        }

        // Defence-in-depth on a cache hit: guard against an unrelated scope
        // reusing the same variable name with a non-Request type.
        if (self::resolveCallerClass($event, \Illuminate\Http\Request::class) === null) {
            return null;
        }

        return $rules;
    }

    /**
     * FormRequest class from a `ValidatedInput<FormRequest>` caller, populated
     * when `safe()` returns `ValidatedInput<static>`.
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
