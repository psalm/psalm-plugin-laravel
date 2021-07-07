<?php declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Helpers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use PhpParser;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\FunctionReturnTypeProviderInterface;
use Psalm\Type;

class RedirectHandler implements FunctionReturnTypeProviderInterface
{

    /**
     * @return array<lowercase-string>
     */
    public static function getFunctionIds(): array
    {
        return ['redirect'];
    }

    /**
     * @param  array<PhpParser\Node\Arg> $call_args
     *
     * @return ?Type\Union
     */
    public static function getFunctionReturnType(FunctionReturnTypeProviderEvent $event) : ?Type\Union
    {
        if (!$event->getCallArgs()) {
            return new Type\Union([
                new Type\Atomic\TNamedObject(Redirector::class)
            ]);
        }

        return new Type\Union([
            new Type\Atomic\TNamedObject(RedirectResponse::class),
        ]);
    }
}
