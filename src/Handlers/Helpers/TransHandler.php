<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Helpers;

use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\FunctionReturnTypeProviderInterface;
use Psalm\Type;

final class TransHandler implements FunctionReturnTypeProviderInterface
{
    /** @inheritDoc */
    #[\Override]
    public static function getFunctionIds(): array
    {
        return ['trans'];
    }

    /** @inheritDoc */
    #[\Override]
    public static function getFunctionReturnType(FunctionReturnTypeProviderEvent $event): Type\Union
    {
        $call_args = $event->getCallArgs();

        if ($call_args !== []) {
            $first_arg_type = $event->getStatementsSource()->getNodeTypeProvider()->getType($call_args[0]->value);

            if ($first_arg_type && $first_arg_type->isString()) {
                return Type::combineUnionTypes(Type::getString(), Type::getArray());
            }
        }

        return Type::getMixed();
    }
}
