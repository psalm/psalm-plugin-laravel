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
 * Manages taint for validated data from FormRequest subclasses.
 *
 * When a MethodReturnTypeProvider overrides the return type of validated(),
 * Psalm skips the stub's @psalm-taint-source annotation. This handler
 * compensates by explicitly adding taint (via AddTaintsInterface) and then
 * selectively removing it for fields with safe rules (via RemoveTaintsInterface).
 *
 * For $request->validated('age') with 'integer' rule:
 *   addTaints()    → ALL_INPUT  (mark as user input)
 *   removeTaints() → ALL_INPUT  (integer rule makes it safe)
 *   net taint      → 0          (clean)
 *
 * For $request->validated('name') with 'string' rule:
 *   addTaints()    → ALL_INPUT  (mark as user input)
 *   removeTaints() → 0          (string rule doesn't sanitize)
 *   net taint      → ALL_INPUT  (tainted)
 *
 * Architecture follows {@see \Psalm\Internal\Provider\AddRemoveTaints\HtmlFunctionTainter}.
 *
 * Performance: fires on every expression. Bail-out chain rejects non-matching
 * expressions fast (instanceof checks first, then method name, then class type).
 */
final class ValidationTaintHandler implements AddTaintsInterface, RemoveTaintsInterface
{
    private const INTERCEPTED_METHODS = [
        'validated' => true,
    ];

    /**
     * Add taint to validated() calls on FormRequest subclasses.
     *
     * This replaces the @psalm-taint-source annotation from the stub, which
     * gets skipped when ValidatedTypeHandler provides a return type.
     */
    #[\Override]
    public static function addTaints(AddRemoveTaintsEvent $event): int
    {
        if (!self::isFormRequestValidatedCall($event)) {
            return 0;
        }

        return TaintKind::ALL_INPUT;
    }

    /**
     * Remove taint from validated('field') calls where the field's validation
     * rules guarantee safe content (e.g. integer, uuid, alpha_num).
     */
    #[\Override]
    public static function removeTaints(AddRemoveTaintsEvent $event): int
    {
        $expr = $event->getExpr();

        if (!$expr instanceof MethodCall) {
            return 0;
        }

        // Must have a literal string first argument (the field key)
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

        $className = self::resolveFormRequestClass($expr, $statementsAnalyzer, $event);

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
     * Check if the expression is a validated() call on a FormRequest subclass.
     */
    private static function isFormRequestValidatedCall(AddRemoveTaintsEvent $event): bool
    {
        $expr = $event->getExpr();

        if (!$expr instanceof MethodCall) {
            return false;
        }

        if (!$expr->name instanceof Identifier) {
            return false;
        }

        $methodName = \strtolower($expr->name->toString());

        if (!isset(self::INTERCEPTED_METHODS[$methodName])) {
            return false;
        }

        $statementsAnalyzer = $event->getStatementsSource();

        if (!$statementsAnalyzer instanceof StatementsAnalyzer) {
            return false;
        }

        return self::resolveFormRequestClass($expr, $statementsAnalyzer, $event) !== null;
    }

    /**
     * Resolve the FormRequest class from a method call's caller type.
     *
     * @return class-string|null
     */
    private static function resolveFormRequestClass(
        MethodCall $expr,
        StatementsAnalyzer $statementsAnalyzer,
        AddRemoveTaintsEvent $event,
    ): ?string {
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
                $isFormRequest = $className === \Illuminate\Foundation\Http\FormRequest::class
                    || $codebase->classExtends($className, \Illuminate\Foundation\Http\FormRequest::class);
            } catch (\Psalm\Exception\UnpopulatedClasslikeException|\InvalidArgumentException) {
                continue;
            }

            if ($isFormRequest) {
                return $className;
            }
        }

        return null;
    }
}
