<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Config;

use Psalm\LaravelPlugin\Providers\FacadeMapProvider;
use Psalm\LaravelPlugin\Util\ConfigKeyResolver;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodParamsProviderInterface;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type;

/**
 * Narrows `Repository::get()` (concrete + contract) and `Config::get()` (facade)
 * to the runtime type reflected from the booted Laravel app.
 *
 * Typed accessors (`string`, `integer`, `float`, `boolean`, `array`,
 * `collection`) are not handled here — their stub return types in
 * `stubs/common/Config/Repository.phpstub` are already precise.
 *
 * See https://github.com/psalm/psalm-plugin-laravel/issues/752.
 */
final class ConfigRepositoryMethodHandler implements MethodReturnTypeProviderInterface, MethodParamsProviderInterface
{
    /**
     * Psalm dispatches `MethodReturnTypeProvider` by exact class name, so the
     * facade FQCN (and any root-namespace alias) must be registered alongside
     * the concrete/contract — otherwise `Config::get()` falls back to the stub.
     *
     * @inheritDoc
     * @return list<string>
     * @psalm-external-mutation-free
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [
            \Illuminate\Config\Repository::class,
            \Illuminate\Contracts\Config\Repository::class,
            \Illuminate\Support\Facades\Config::class,
            ...FacadeMapProvider::getFacadeClasses(\Illuminate\Config\Repository::class),
        ];
    }

    /** @inheritDoc */
    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Type\Union
    {
        if ($event->getMethodNameLowercase() !== 'get') {
            return null;
        }

        return ConfigKeyResolver::resolveFromCallArgs(
            $event->getCallArgs(),
            $event->getSource()->getNodeTypeProvider(),
        );
    }

    /**
     * Synthesise `get()` params for the Facade FQCN (`@method`-declared only).
     * Without this, Psalm 7 crashes with
     * `Cannot get method params for ...::get` — same crash class as #454/#854.
     * Defers to source for the real Repository + contract so future Laravel
     * signature changes are picked up automatically.
     *
     * @inheritDoc
     * @psalm-mutation-free
     */
    #[\Override]
    public static function getMethodParams(MethodParamsProviderEvent $event): ?array
    {
        if ($event->getMethodNameLowercase() !== 'get') {
            return null;
        }

        $fqcn = $event->getFqClasslikeName();

        if (
            $fqcn === \Illuminate\Config\Repository::class
            || $fqcn === \Illuminate\Contracts\Config\Repository::class
        ) {
            return null;
        }

        // `@method static mixed get(array|string $key, mixed $default = null)`
        $stringOrArray = new Type\Union([
            new Type\Atomic\TString(),
            new Type\Atomic\TArray([Type::getArrayKey(), Type::getMixed()]),
        ]);

        return [
            new FunctionLikeParameter('key', false, $stringOrArray, $stringOrArray, is_optional: false),
            new FunctionLikeParameter(
                'default',
                false,
                Type::getMixed(),
                Type::getMixed(),
                is_optional: true,
                default_type: Type::getNull(),
            ),
        ];
    }
}
