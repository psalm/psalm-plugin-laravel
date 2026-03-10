<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Util;

use PhpParser\Node\Expr\MethodCall;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements\Expression\Call\MethodCallAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

use function array_key_exists;
use function implode;
use function in_array;

final class ProxyMethodReturnTypeProvider
{
    /**
     * Cache keyed by "{proxyClass}::{methodName}({argTypes})" → resolved Union.
     * Argument types are included because some methods (e.g. find(), findOrFail())
     * have conditional return types that depend on argument types.
     *
     * @var array<string, Union>
     */
    private static array $cache = [];

    /**
     * Psalm struggles with saying "this method returns whatever class X with the same method returns. This performs
     * a fake method call to get the analyzed proxy method return type
     * @psalm-param TNamedObject $typeToCall the fake object to execute a fake method call on
     */
    public static function executeFakeCall(
        StatementsAnalyzer $statements_analyzer,
        MethodCall $fake_method_call,
        Context $context,
        TNamedObject $typeToCall,
    ): ?Union {
        $methodName = $fake_method_call->name;
        if ($methodName instanceof \PhpParser\Node\Identifier) {
            $argSignatures = [];
            foreach ($fake_method_call->getArgs() as $arg) {
                $argType = $statements_analyzer->node_data->getType($arg->value);
                $argSignatures[] = $argType !== null ? $argType->getId() : 'mixed';
            }
            $cacheKey = $typeToCall->getId() . '::' . $methodName->name . '(' . implode(',', $argSignatures) . ')';

            if (array_key_exists($cacheKey, self::$cache)) {
                return self::$cache[$cacheKey];
            }
        } else {
            $cacheKey = null;
        }

        $old_data_provider = $statements_analyzer->node_data;
        $statements_analyzer->node_data = clone $statements_analyzer->node_data;

        $context = clone $context;
        $context->inside_call = true;

        $context->vars_in_scope['$fakeProxyObject'] = new Union([
            $typeToCall,
        ]);

        $suppressed_issues = $statements_analyzer->getSuppressedIssues();
        $addedSuppression = !in_array('PossiblyInvalidMethodCall', $suppressed_issues, true);

        if ($addedSuppression) {
            $statements_analyzer->addSuppressedIssues(['PossiblyInvalidMethodCall']);
        }

        try {
            if (
                MethodCallAnalyzer::analyze(
                    $statements_analyzer,
                    $fake_method_call,
                    $context,
                    false,
                ) === false
            ) {
                // Don't cache — analysis failure is transient
                return null;
            }

            $returnType = $statements_analyzer->node_data->getType($fake_method_call);

            if ($cacheKey !== null && $returnType !== null) {
                self::$cache[$cacheKey] = $returnType;
            }

            return $returnType;
        } finally {
            if ($addedSuppression) {
                $statements_analyzer->removeSuppressedIssues(['PossiblyInvalidMethodCall']);
            }

            $statements_analyzer->node_data = $old_data_provider;
        }
    }
}
