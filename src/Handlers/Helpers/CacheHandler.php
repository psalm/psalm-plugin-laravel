<?php

namespace Psalm\LaravelPlugin\Handlers\Helpers;

use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\FunctionReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Atomic\TBool;
use Psalm\Type\Atomic\TNamedObject;

final class CacheHandler implements FunctionReturnTypeProviderInterface
{
    public static function getFunctionIds(): array
    {
        return ['cache'];
    }

    public static function getFunctionReturnType(FunctionReturnTypeProviderEvent $event): Type\Union
    {
        $call_args = $event->getCallArgs();

        if ($call_args === []) {
            return new Type\Union([
                new TNamedObject(\Illuminate\Cache\CacheManager::class)
            ]);
        }

        $first_arg_type = $event->getStatementsSource()->getNodeTypeProvider()->getType($call_args[0]->value);

        /** @see \Illuminate\Contracts\Cache\Store::put() */
        if ($first_arg_type && $first_arg_type->isArray()) {
            return new Type\Union([new TBool()]);
        }

        /**
         * For cases:
         *  - unknown arg type
         *  - string arg type ($first_arg_type->isString())
         * @see \Illuminate\Contracts\Cache\Store::get()
         */
        return new Type\Union([new Type\Atomic\TMixed()]);
    }
}
