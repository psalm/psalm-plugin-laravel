<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Config;

use Psalm\LaravelPlugin\Stubs\FacadeMapProvider;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodParamsProviderInterface;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type;

/**
 * Narrows `Repository::get()`/`collection()` (concrete + contract) and the
 * matching `Config` facade calls to the runtime type reflected from the booted
 * Laravel app. `collection($key)` wraps the same reflected array as `get($key)`
 * in `Collection<keyType, valueType>` (see {@see ConfigKeyResolver}).
 *
 * The other typed accessors (`string`, `integer`, `float`, `boolean`, `array`)
 * are not handled here — their stub return types in
 * `stubs/common/Config/Repository.phpstub` are already precise.
 *
 * See https://github.com/psalm/psalm-plugin-laravel/issues/752
 * and https://github.com/psalm/psalm-plugin-laravel/issues/1150.
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
        $nodeTypeProvider = $event->getSource()->getNodeTypeProvider();

        return match ($event->getMethodNameLowercase()) {
            'get' => ConfigKeyResolver::resolveFromCallArgs($event->getCallArgs(), $nodeTypeProvider),
            'collection' => ConfigKeyResolver::resolveCollectionFromCallArgs($event->getCallArgs(), $nodeTypeProvider),
            default => null,
        };
    }

    /**
     * Synthesise `get()`/`collection()` params for the Facade FQCN
     * (`@method`-declared only). Without this, registering a return-type
     * provider for these pseudo-methods makes Psalm 7 crash with
     * `Cannot get method params for ...` — same crash class as #454/#854.
     * Defers to source for the real Repository + contract so future Laravel
     * signature changes are picked up automatically.
     *
     * @inheritDoc
     * @psalm-mutation-free
     */
    #[\Override]
    public static function getMethodParams(MethodParamsProviderEvent $event): ?array
    {
        $method = $event->getMethodNameLowercase();

        if ($method !== 'get' && $method !== 'collection') {
            return null;
        }

        $fqcn = $event->getFqClasslikeName();

        if (
            $fqcn === \Illuminate\Config\Repository::class
            || $fqcn === \Illuminate\Contracts\Config\Repository::class
        ) {
            return null;
        }

        return $method === 'get' ? self::synthesizeGetParams() : self::synthesizeCollectionParams();
    }

    /**
     * `@method static mixed get(array|string $key, mixed $default = null)`
     *
     * @return list<FunctionLikeParameter>
     * @psalm-pure
     */
    private static function synthesizeGetParams(): array
    {
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

    /**
     * `@method static Collection collection(string $key, \Closure|array|null $default = null)`
     *
     * The tightened `\Closure|array|null` default mirrors the concrete stub
     * (`stubs/common/Config/Repository.phpstub`), so a scalar default is
     * rejected on the facade exactly as it is on the Repository.
     *
     * @return list<FunctionLikeParameter>
     * @psalm-pure
     */
    private static function synthesizeCollectionParams(): array
    {
        $key = new Type\Union([new Type\Atomic\TString()]);

        // Mirror the concrete stub's `(\Closure():(array<array-key, mixed>|null))` exactly:
        // a zero-arg closure returning array|null. A bare TClosure() would also accept
        // `fn () => 'scalar'`, which Laravel rejects at runtime (the resolved value must be
        // an array), so the facade must reproduce the same return-type constraint.
        $arrayOrNull = new Type\Union([
            new Type\Atomic\TArray([Type::getArrayKey(), Type::getMixed()]),
            new Type\Atomic\TNull(),
        ]);
        $default = new Type\Union([
            new Type\Atomic\TClosure(params: [], return_type: $arrayOrNull),
            new Type\Atomic\TArray([Type::getArrayKey(), Type::getMixed()]),
            new Type\Atomic\TNull(),
        ]);

        return [
            new FunctionLikeParameter('key', false, $key, $key, is_optional: false),
            new FunctionLikeParameter(
                'default',
                false,
                $default,
                $default,
                is_optional: true,
                default_type: Type::getNull(),
            ),
        ];
    }
}
