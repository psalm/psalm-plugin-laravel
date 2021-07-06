<?php

namespace Psalm\LaravelPlugin\Handlers\Helpers;

use PhpParser;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\StatementsSource;
use Psalm\Type;

class TransHandler implements \Psalm\Plugin\Hook\FunctionReturnTypeProviderInterface
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
        if ($call_args) {
            $first_arg_type = $statements_source->getNodeTypeProvider()->getType($call_args[0]->value);

            if ($first_arg_type && $first_arg_type->isString()) {
                return \Psalm\Type::getString();
            }
        }

        return Type::getMixed();
    }
}
