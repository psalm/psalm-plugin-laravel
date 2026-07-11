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
 * Narrows `Cache` facade static calls (`Cache::store()`, `Cache::driver()`,
 * `Cache::memo()`) to the concrete `\Illuminate\Cache\Repository`.
 *
 * The facade ships hardcoded `@method static \Illuminate\Contracts\Cache\Repository`
 * tags for these, and a facade's `@method` pseudo-methods shadow any real method a
 * redeclaring stub could add, so the interface return can only be overridden by a
 * handler. At runtime the value is the concrete Repository: built-in drivers are
 * wrapped by `CacheManager::repository()` and `memo()` wraps in a MemoizedStore.
 * Custom `Cache::extend()` drivers return whatever the creator produces; nothing
 * enforces the concrete class, but Laravel's docs direct extension creators to
 * return `Cache::repository(...)`, so a custom driver is concrete by convention.
 * Narrowing follows that convention (as Larastan does) and restores the
 * concrete-only surface the interface hides: `flexible()`, `tags()` and the
 * Macroable helpers.
 *
 * The instance-call paths (injected `CacheManager`, `cache()->driver()`) resolve
 * through the concrete return declared in stubs/common/Cache/CacheManager.phpstub;
 * only the facade static path needs this handler.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1230
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
        // Psalm dispatches return-type providers by exact FQCN, so the root-namespace
        // alias (`\Cache`) must be registered alongside the facade FQCN — otherwise
        // `\Cache::driver()` falls back to the inherited `@method` tag. FacadeMapProvider
        // maps the `cache` service (a CacheManager instance) to both.
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

        // Invariant: this return provider must never fire for a method whose params the
        // params provider cannot supply. Returning a non-null type drives Psalm into
        // checkMethodArgs() -> getMethodParams(); a null there fatals with "Cannot get
        // method params". Both providers therefore gate on the same pseudo-method lookup,
        // so when the facade lacks the `@method static` tag the handler stays silent and
        // Psalm emits its normal issue instead of crashing.
        if (self::pseudoMethodParams($event->getSource()->getCodebase(), $methodNameLower) === null) {
            return null;
        }

        return new Union([new TNamedObject(\Illuminate\Cache\Repository::class)]);
    }

    /**
     * Supply params for the methods we retype. Psalm runs `checkMethodArgs()` — which
     * calls `getMethodParams()` — only after a return-type provider yields a non-null
     * type for a pseudo `@method` call; without a params provider that path fatals with
     * "Cannot get method params". Reusing the facade's own declared `@method` params
     * keeps the accepted argument types (`string|null` before Laravel 13.5.0,
     * `\UnitEnum|string|null` from 13.5.0) version-correct without a hardcoded list.
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
            // Facade storage missing — Psalm didn't scan the Cache facade. Nothing to
            // read; its `@method` tags remain the authoritative fallback for typing.
            return null;
        }

        return $storage->pseudo_static_methods[$methodNameLower]->params ?? null;
    }
}
