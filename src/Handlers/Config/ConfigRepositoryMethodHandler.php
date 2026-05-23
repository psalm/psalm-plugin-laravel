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
 * Narrows `Repository::get('some.key', $default)` (and the equivalent call on the
 * `\Illuminate\Contracts\Config\Repository` contract) to the runtime type of
 * `some.key`. Cross-receiver consistency with `Config::get(...)` is handled by
 * Psalm's facade pipeline — the facade resolves to the concrete Repository and
 * triggers this handler.
 *
 * Other typed accessors (`string`, `integer`, `float`, `boolean`, `array`,
 * `collection`) are intentionally not handled here: their stub return types in
 * `stubs/common/Config/Repository.phpstub` are already precise (the stub even
 * tightens `$default` to reject scalars that would crash at runtime).
 *
 * Solution 3 of https://github.com/psalm/psalm-plugin-laravel/issues/752.
 */
final class ConfigRepositoryMethodHandler implements MethodReturnTypeProviderInterface, MethodParamsProviderInterface
{
    /**
     * Register for the concrete Repository, the contract, the Config facade,
     * and any root-namespace alias resolving to the Repository binding.
     * Psalm dispatches MethodReturnTypeProvider hooks by exact class name, so
     * `Config::get(...)` (which uses `__callStatic` on the facade) needs the
     * facade FQCN listed here — otherwise the facade pipeline falls through
     * to the stub and the call site stays at `mixed`.
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

        // resolveFromCallArgs returns null for shapes the stub handles:
        //   - 0 args
        //   - array first arg (multi-key getMany form)
        //   - dynamic first arg → mixed
        return ConfigKeyResolver::resolveFromCallArgs(
            $event->getCallArgs(),
            $event->getSource()->getNodeTypeProvider(),
        );
    }

    /**
     * Provide explicit `get()` params for the Config facade FQCN and any
     * root-namespace alias that proxies to it.
     *
     * In Psalm 7, registering a class for a MethodReturnTypeProviderInterface
     * and then returning null from getMethodParams for a method that only
     * exists as a `@method` annotation on that class causes an
     * UnexpectedValueException: Cannot get method params for ...::get crash.
     * The same crash hit AuthMethodHandler — see #454 and #854.
     *
     * For the concrete Repository and the contract, defer to Psalm by returning
     * null: get() is a real method on both, and letting Psalm derive params
     * from the source keeps the signature in sync with future Laravel
     * versions automatically.
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

        if ($fqcn === \Illuminate\Config\Repository::class
            || $fqcn === \Illuminate\Contracts\Config\Repository::class
        ) {
            return null;
        }

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
