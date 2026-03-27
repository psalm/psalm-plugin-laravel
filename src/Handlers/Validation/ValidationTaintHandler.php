<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Validation;

use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\AddTaintsInterface;
use Psalm\Plugin\EventHandler\Event\AddRemoveTaintsEvent;
use Psalm\Plugin\EventHandler\RemoveTaintsInterface;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\TaintKind;
use Psalm\Type\Union;

/**
 * Manages taint for validated data from FormRequest and Request.
 *
 * When a MethodReturnTypeProvider overrides the return type of validated() or validate(),
 * Psalm skips the stub's @psalm-taint-source annotation. This handler compensates by
 * explicitly adding taint (via AddTaintsInterface) and then selectively removing it
 * for fields with safe rules (via RemoveTaintsInterface).
 *
 * Handles:
 *   $request->validated('field')    — FormRequest, per-field add+remove
 *   $request->safe()                — FormRequest, add taint
 *   $request->validate([...])       — Request, add taint to return value
 *
 * Architecture follows {@see \Psalm\Internal\Provider\AddRemoveTaints\HtmlFunctionTainter}.
 *
 * Performance: fires on every expression. Bail-out chain rejects non-matching
 * expressions fast (instanceof checks first, then method name, then class type).
 */
final class ValidationTaintHandler implements AddTaintsInterface, RemoveTaintsInterface
{
    /**
     * Add taint to validated()/validate()/safe() calls.
     *
     * This replaces the @psalm-taint-source annotation from stubs, which
     * gets skipped when ValidatedTypeHandler provides a return type.
     */
    #[\Override]
    public static function addTaints(AddRemoveTaintsEvent $event): int
    {
        if (self::isValidationMethodCall($event) !== null) {
            return TaintKind::ALL_INPUT;
        }

        return 0;
    }

    /**
     * Remove taint from validated('field') calls where the field's validation
     * rules guarantee safe content (e.g. integer, uuid, alpha_num).
     *
     * Only applies to FormRequest::validated('field') with a literal key —
     * validate()/safe() return full arrays where per-field taint removal is not possible.
     */
    #[\Override]
    public static function removeTaints(AddRemoveTaintsEvent $event): int
    {
        $expr = $event->getExpr();

        if (!$expr instanceof MethodCall || !$expr->name instanceof Identifier) {
            return 0;
        }

        if (\strtolower($expr->name->toString()) !== 'validated') {
            return 0;
        }

        $args = $expr->getArgs();

        if ($args === []) {
            return 0;
        }

        $statementsAnalyzer = $event->getStatementsSource();

        if (!$statementsAnalyzer instanceof StatementsAnalyzer) {
            return 0;
        }

        $firstArgType = $statementsAnalyzer->node_data->getType($args[0]->value);

        if (!$firstArgType instanceof Union || !$firstArgType->isSingleStringLiteral()) {
            return 0;
        }

        $fieldKey = $firstArgType->getSingleStringLiteral()->value;

        $className = self::resolveCallerClass($event, \Illuminate\Foundation\Http\FormRequest::class);

        if ($className === null) {
            return 0;
        }

        $rules = ValidationRuleAnalyzer::getRulesForFormRequest($className);

        if ($rules === null || !isset($rules[$fieldKey])) {
            return 0;
        }

        return $rules[$fieldKey]->removedTaints;
    }

    /**
     * Check if the expression is a validated()/validate()/safe() call on Request/FormRequest.
     *
     * @return 'validated'|'validate'|'safe'|null  The matched method name, or null
     */
    private static function isValidationMethodCall(AddRemoveTaintsEvent $event): ?string
    {
        $expr = $event->getExpr();

        if (!$expr instanceof MethodCall) {
            return null;
        }

        if (!$expr->name instanceof Identifier) {
            return null;
        }

        $methodName = \strtolower($expr->name->toString());

        if (!in_array($methodName, ['validated', 'validate', 'safe'], true)) {
            return null;
        }

        // validated() and safe() are FormRequest methods, validate() is on Request
        $baseClass = ($methodName === 'validated' || $methodName === 'safe')
            ? \Illuminate\Foundation\Http\FormRequest::class
            : \Illuminate\Http\Request::class;

        if (self::resolveCallerClass($event, $baseClass) !== null) {
            return $methodName;
        }

        return null;
    }

    /**
     * Resolve a class from the method call's caller type that matches or extends the given base class.
     *
     * Shared by addTaints (via isValidationMethodCall) and removeTaints to avoid
     * duplicating the classExtends resolution logic.
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
            if (!$atomic instanceof TNamedObject) {
                continue;
            }

            /** @var class-string $className */
            $className = $atomic->value;

            try {
                if ($className === $baseClass || $codebase->classExtends($className, $baseClass)) {
                    return $className;
                }
            } catch (\Psalm\Exception\UnpopulatedClasslikeException|\InvalidArgumentException) {
                continue;
            }
        }

        return null;
    }
}
