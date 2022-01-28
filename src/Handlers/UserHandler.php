<?php

namespace Psalm\LaravelPlugin\Handlers;

use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;

final class UserHandler implements MethodReturnTypeProviderInterface
{
    public static function getClassLikeNames(): array
    {
        return [
            \Illuminate\Support\Facades\Auth::class,
            'Auth',
            \Illuminate\Http\Request::class
        ];
    }

    public static function getMethodReturnType(
        MethodReturnTypeProviderEvent $event
    ): ?Type\Union {
        $method_name_lowercase = $event->getMethodNameLowercase();
        $class_name = $event->getFqClasslikeName();

        if (
            $class_name === \Illuminate\Support\Facades\Auth::class ||
            $class_name === 'Auth'
        ) {
            if ($method_name_lowercase === 'user') {
                return new Type\Union([
                    new Type\Atomic\TNamedObject('App\Models\User'),
                    new Type\Atomic\TNull(),
                ]);
            }

            if ($method_name_lowercase === 'loginusingid') {
                return new Type\Union([
                    new Type\Atomic\TNamedObject('App\Models\User'),
                    new Type\Atomic\TFalse(),
                ]);
            }
        }

        if ($class_name === \Illuminate\Http\Request::class) {
            if ($method_name_lowercase === 'user') {
                return new Type\Union([
                    new Type\Atomic\TNamedObject('App\Models\User'),
                    new Type\Atomic\TNull(),
                ]);
            }
        }

        return null;
    }
}
