<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Helpers;

use Psalm\LaravelPlugin\Handlers\Config\EnvDefaultType;
use Psalm\LaravelPlugin\Internal\Arg;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\FunctionReturnTypeProviderInterface;
use Psalm\Type;

/**
 * Narrows the return type of env() from its default argument; the model is {@see EnvDefaultType}.
 */
final class EnvHandler implements FunctionReturnTypeProviderInterface
{
    /**
     * @inheritDoc
     * @psalm-pure
     */
    #[\Override]
    public static function getFunctionIds(): array
    {
        return ['env'];
    }

    /** @inheritDoc */
    #[\Override]
    public static function getFunctionReturnType(FunctionReturnTypeProviderEvent $event): Type\Union
    {
        $args = $event->getCallArgs();
        $default = Arg::typeAt($args, $event->getStatementsSource(), 1);

        return EnvDefaultType::returnTypeForDefault(
            $default instanceof Type\Union ? EnvDefaultType::defaultWithProvenNull($args[1] ?? null, $default) : null,
        );
    }
}
