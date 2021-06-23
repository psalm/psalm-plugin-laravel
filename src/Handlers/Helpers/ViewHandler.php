<?php

namespace Psalm\LaravelPlugin\Handlers\Helpers;

use PhpParser;
use Psalm\Context;
use Psalm\CodeLocation;
use Psalm\Type;
use Psalm\StatementsSource;

class ViewHandler implements \Psalm\Plugin\Hook\FunctionReturnTypeProviderInterface
{
    public static function getFunctionIds() : array
    {
        return ['view'];
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
            return new Type\Union([
                new \Psalm\Type\Atomic\TNamedObject(\Illuminate\View\View::class)
            ]);
        }

        return new Type\Union([
            new \Psalm\Type\Atomic\TNamedObject(\Illuminate\Contracts\View\Factory::class)
        ]);
    }
}
