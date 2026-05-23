<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Helpers;

use Psalm\LaravelPlugin\Util\ConfigKeyResolver;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\FunctionReturnTypeProviderInterface;
use Psalm\Type;

/**
 * Narrows `config('some.key', $default)` to the runtime value from the booted
 * Laravel app (generalized — see {@see \Psalm\LaravelPlugin\Util\ConfigValueReflector}).
 *
 * Defers to the helpers stub on non-narrowable shapes (no args → Repository;
 * array first arg → null setter form; dynamic key → mixed).
 *
 * See https://github.com/psalm/psalm-plugin-laravel/issues/752.
 */
final class ConfigHelperHandler implements FunctionReturnTypeProviderInterface
{
    /**
     * @inheritDoc
     * @psalm-pure
     */
    #[\Override]
    public static function getFunctionIds(): array
    {
        return ['config'];
    }

    /** @inheritDoc */
    #[\Override]
    public static function getFunctionReturnType(FunctionReturnTypeProviderEvent $event): ?Type\Union
    {
        return ConfigKeyResolver::resolveFromCallArgs(
            $event->getCallArgs(),
            $event->getStatementsSource()->getNodeTypeProvider(),
        );
    }
}
