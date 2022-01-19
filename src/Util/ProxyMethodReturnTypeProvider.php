<?php

namespace Psalm\LaravelPlugin\Util;

use PhpParser\Node\Expr\MethodCall;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements\Expression\Call\MethodCallAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

use function in_array;

final class ProxyMethodReturnTypeProvider
{
    /**
     * Psalm struggles with saying "this method returns whatever class X with the same method returns. This performs
     * a fake method call to get the analyzed proxy method return type
     * @psalm-param class-string $className
     * @psalm-param TNamedObject $typeToCall the fake object to execute a fake method call on
     */
    public static function executeFakeCall(
        StatementsAnalyzer $statements_analyzer,
        MethodCall $fake_method_call,
        Context $context,
        TNamedObject $typeToCall
    ): ?Union {
        $old_data_provider = $statements_analyzer->node_data;
        $statements_analyzer->node_data = clone $statements_analyzer->node_data;

        $context = clone $context;
        $context->inside_call = true;

        $context->vars_in_scope['$fakeProxyObject'] = new Union([
            $typeToCall,
        ]);

        $suppressed_issues = $statements_analyzer->getSuppressedIssues();

        if (!in_array('PossiblyInvalidMethodCall', $suppressed_issues, true)) {
            $statements_analyzer->addSuppressedIssues(['PossiblyInvalidMethodCall']);
        }

        if (
            MethodCallAnalyzer::analyze(
                $statements_analyzer,
                $fake_method_call,
                $context,
                false
            ) === false
        ) {
            return null;
        }

        if (!in_array('PossiblyInvalidMethodCall', $suppressed_issues, true)) {
            $statements_analyzer->removeSuppressedIssues(['PossiblyInvalidMethodCall']);
        }

        $returnType = $statements_analyzer->node_data->getType($fake_method_call);

        $statements_analyzer->node_data = $old_data_provider;

        return $returnType;
    }
}
