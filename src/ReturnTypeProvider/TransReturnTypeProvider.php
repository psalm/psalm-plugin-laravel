<?php

namespace Psalm\LaravelPlugin\ReturnTypeProvider;

use PhpParser;
use Psalm\Context;
use Psalm\CodeLocation;
use Psalm\Type;
use Psalm\StatementsSource;

class TransReturnTypeProvider implements \Psalm\Plugin\Hook\FunctionReturnTypeProviderInterface
{
    public static function getFunctionIds() : array
    {
        return ['trans'];
    }

    /**
     * @param  array<PhpParser\Node\Arg>    $call_args
     */
    public static function getFunctionReturnType(
        StatementsSource $statements_source,
        string $function_id,
        array $call_args,
        Context $context,
        CodeLocation $code_location
    ) : Type\Union {
        if ($call_args
            && isset($call_args[0]->value->inferredType)
        ) {
            if ($call_args[0]->value->inferredType->isString()) {
                return \Psalm\Type::getString();
            }
        }

        return Type::getMixed();
    }
}
