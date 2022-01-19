<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Helpers;

use Illuminate\Contracts\Routing\UrlGenerator;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\FunctionReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

final class UrlHandler implements FunctionReturnTypeProviderInterface
{
    public static function getFunctionIds(): array
    {
        return ['url'];
    }

    public static function getFunctionReturnType(FunctionReturnTypeProviderEvent $event): ?Union
    {
        if (!$event->getCallArgs()) {
            return new Union([
                new TNamedObject(UrlGenerator::class),
            ]);
        }

        return Type::getString();
    }
}
