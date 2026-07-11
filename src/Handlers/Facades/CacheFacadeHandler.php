<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Facades;

use Illuminate\Cache\CacheManager;
use Illuminate\Support\Facades\Cache;
use Psalm\Codebase;
use Psalm\LaravelPlugin\Stubs\FacadeMapProvider;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodParamsProviderInterface;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\StatementsSource;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Narrows `Cache::store()/driver()/memo()` static calls to the concrete
 * `\Illuminate\Cache\Repository` (#1230). A handler is required because the
 * facade's `@method` pseudo-tags shadow any real method a redeclaring stub adds;
 * the instance paths are covered by stubs/common/Cache/CacheManager.phpstub,
 * which also documents why the concrete return is sound.
 *
 * @internal
 */
final class CacheFacadeHandler implements MethodReturnTypeProviderInterface, MethodParamsProviderInterface
{
    private const NARROWED_METHODS = ['store', 'driver', 'memo'];

    /**
     * @inheritDoc
     * @return list<string>
     * @psalm-external-mutation-free
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        // Psalm dispatches providers by exact FQCN; the `\Cache` root alias must be
        // registered too or `\Cache::driver()` keeps the inherited `@method` tag.
        return [
            Cache::class,
            ...FacadeMapProvider::getFacadeClasses(CacheManager::class),
        ];
    }

    /** @inheritDoc */
    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $methodNameLower = $event->getMethodNameLowercase();

        if (!\in_array($methodNameLower, self::NARROWED_METHODS, true)) {
            return null;
        }

        // Invariant: never fire when the params provider would return null — a non-null
        // return type on a pseudo-method whose params can't be supplied fatals Psalm
        // with "Cannot get method params". Both providers gate on the same lookup.
        if (self::pseudoMethodParams($event->getSource()->getCodebase(), $methodNameLower) === null) {
            return null;
        }

        return new Union([new TNamedObject(\Illuminate\Cache\Repository::class)]);
    }

    /**
     * Mandatory companion to the return provider (see the invariant above). Reusing
     * the facade's own `@method` params keeps argument types version-correct
     * (`string|null` pre-13.5.0, `\UnitEnum|string|null` after) without a hardcoded list.
     *
     * @inheritDoc
     */
    #[\Override]
    public static function getMethodParams(MethodParamsProviderEvent $event): ?array
    {
        $methodNameLower = $event->getMethodNameLowercase();

        if (!\in_array($methodNameLower, self::NARROWED_METHODS, true)) {
            return null;
        }

        $source = $event->getStatementsSource();

        if (!$source instanceof StatementsSource) {
            return null;
        }

        return self::pseudoMethodParams($source->getCodebase(), $methodNameLower);
    }

    /**
     * @return ?list<\Psalm\Storage\FunctionLikeParameter>
     * @psalm-mutation-free
     */
    private static function pseudoMethodParams(Codebase $codebase, string $methodNameLower): ?array
    {
        try {
            $storage = $codebase->classlike_storage_provider->get(Cache::class);
        } catch (\InvalidArgumentException) {
            // Facade never scanned — nothing to read, vendor @method tags stay authoritative.
            return null;
        }

        return $storage->pseudo_static_methods[$methodNameLower]->params ?? null;
    }
}
