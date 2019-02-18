<?php

namespace Psalm\LaravelPlugin\ReturnTypeProvider;

use PhpParser;
use Psalm\Context;
use Psalm\CodeLocation;
use Psalm\Type;
use Psalm\StatementsSource;

class AuthReturnTypeProvider implements \Psalm\Plugin\Hook\FunctionReturnTypeProviderInterface
{
    public static function getFunctionIds() : array
    {
        return ['auth'];
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
    	if (!$call_args) {
            $return_type_candidate = new Type\Union([
                new \Psalm\Type\Atomic\TNamedObject(\Illuminate\Support\Facades\Auth::class)
            ]);
        }

        return Type::getMixed();
    }
}