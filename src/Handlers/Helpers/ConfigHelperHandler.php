<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Helpers;

use Psalm\LaravelPlugin\Util\ConfigKeyResolver;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\FunctionReturnTypeProviderInterface;
use Psalm\Type;

/**
 * Narrows `config('some.key', $default)` to the runtime type of `some.key` from
 * the booted Laravel app, generalized so env-driven scalars don't collapse to
 * their observed-default literal (see {@see \Psalm\LaravelPlugin\Util\ConfigValueReflector}).
 *
 * Defers to the existing helpers stub when the call shape is incompatible with
 * key-based reflection:
 *
 *  - `config()`           → stub returns Repository
 *  - `config(['k' => v])` → stub returns null (setter form)
 *  - `config($dynamicKey)` → stub returns mixed (key cannot be statically resolved)
 *
 * Solution 3 of https://github.com/psalm/psalm-plugin-laravel/issues/752.
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
        // resolveFromCallArgs returns null for shapes the stub handles:
        //   - 0 args → Repository
        //   - array first arg → null (setter form)
        //   - dynamic first arg → mixed
        return ConfigKeyResolver::resolveFromCallArgs(
            $event->getCallArgs(),
            $event->getStatementsSource()->getNodeTypeProvider(),
        );
    }
}
