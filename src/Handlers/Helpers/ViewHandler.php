<?php

namespace Psalm\LaravelPlugin\Handlers\Helpers;

use Illuminate\Contracts\View\Factory;
use Illuminate\View\View;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\FunctionReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;

class ViewHandler implements FunctionReturnTypeProviderInterface
{
    public static function getFunctionIds() : array
    {
        return ['view'];
    }

    public static function getFunctionReturnType(FunctionReturnTypeProviderEvent $event) : Type\Union
    {
        if ($event->getCallArgs()) {
            return new Type\Union([
                new TNamedObject(View::class)
            ]);
        }

        return new Type\Union([
            new TNamedObject(Factory::class)
        ]);
    }
}
