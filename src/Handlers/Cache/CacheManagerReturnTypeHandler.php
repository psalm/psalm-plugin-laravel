<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Cache;

use Illuminate\Cache\CacheManager;
use Illuminate\Support\Facades\Cache;
use Psalm\Codebase;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Stubs\FacadeMapProvider;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodParamsProviderInterface;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\StatementsSource;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Narrows `CacheManager::store()`, `driver()` and `memo()` to the concrete
 * `\Illuminate\Cache\Repository` they always return at runtime — via the real
 * manager (injected, `cache()->driver()`, subclasses) and via the `Cache` facade
 * plus its configured root aliases. Their declared return is the `Repository`
 * interface, which hides the concrete-only surface `flexible()`, `tags()` and the
 * Macroable helpers (issue #1230). Built-in drivers wrap through `repository()` and
 * `memo()` wraps a MemoizedStore; Laravel's documented extension recipe returns
 * `Cache::repository(...)`, so a nonconforming custom creator is the accepted
 * bounded trade-off — the contract is not widened globally. A stub cannot cover the
 * facade path because a facade's `@method` tags shadow any redeclared method, hence
 * a handler; real-method calls defer their params to reflection so the `\UnitEnum`
 * widening at Laravel 13.5.0 stays version-correct with no duplicated signature.
 *
 * @internal
 */
final class CacheManagerReturnTypeHandler implements MethodReturnTypeProviderInterface, MethodParamsProviderInterface
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
        // Psalm dispatches by exact FQCN. CacheManager covers the real manager and, via
        // the declaring-method fallback, its subclasses. The facade FQCN plus the app's
        // configured root aliases (`\Cache`) cover the static pseudo-method path.
        return [
            CacheManager::class,
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

        $codebase = $event->getSource()->getCodebase();

        // Real-method calls (manager and subclasses): the params provider defers to
        // reflection, which never fatals, so narrow the return unconditionally.
        if (self::isRealMethod($codebase, $event->getFqClasslikeName(), $methodNameLower)) {
            return self::concreteRepository();
        }

        // Facade/alias pseudo-method calls: returning a non-null type drives Psalm into
        // checkMethodArgs() -> getMethodParams(), which fatals with "Cannot get method
        // params" if the params provider yields null. Gate on the same pseudo-method
        // lookup the params provider uses so the two never desync; when the facade lacks
        // the `@method static` tag the handler stays silent and Psalm emits its own issue.
        if (self::pseudoMethodParams($codebase, $methodNameLower) === null) {
            return null;
        }

        return self::concreteRepository();
    }

    /** @inheritDoc */
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

        $codebase = $source->getCodebase();

        // Real-method calls keep Laravel's actual, version-correct signature.
        if (self::isRealMethod($codebase, $event->getFqClasslikeName(), $methodNameLower)) {
            return null;
        }

        // Facade/alias pseudo-method calls have no real params; copy the canonical
        // facade's `@method static` params so accepted argument types stay accurate.
        return self::pseudoMethodParams($codebase, $methodNameLower);
    }

    /** @psalm-pure */
    private static function concreteRepository(): Union
    {
        return new Union([new TNamedObject(\Illuminate\Cache\Repository::class)]);
    }

    /**
     * True when $fqClassName (or an ancestor) declares $methodNameLower as a real method,
     * false when it exists only as a `@method` pseudo-method. This is the single
     * discriminator both providers share; `methodExists()` excludes pseudo-methods by
     * default (its `$with_pseudo` flag stays false).
     *
     * Psalm 6's `Internal\Codebase\Methods::methodExists()` takes the `MethodIdentifier`
     * as its first argument (no leading `$codebase`, unlike Psalm 7); the public
     * `Codebase::methodExists()` wrapper always passes `with_pseudo: true`, so this bypass
     * is required either way.
     *
     * @param lowercase-string $methodNameLower
     */
    private static function isRealMethod(Codebase $codebase, string $fqClassName, string $methodNameLower): bool
    {
        return $codebase->methods->methodExists(
            new MethodIdentifier($fqClassName, $methodNameLower),
        );
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
