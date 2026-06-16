<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Application;

use Psalm\LaravelPlugin\Providers\ApplicationInterfaceProvider;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;
use Psalm\LaravelPlugin\Util\ContainerResolver;
use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\FunctionReturnTypeProviderInterface;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

final class ContainerHandler implements AfterClassLikeVisitInterface, FunctionReturnTypeProviderInterface, MethodReturnTypeProviderInterface
{
    /**
     * @inheritDoc
     * @psalm-pure
     */
    #[\Override]
    public static function getFunctionIds(): array
    {
        return ['app', 'resolve'];
    }

    /** @inheritDoc */
    #[\Override]
    public static function getFunctionReturnType(FunctionReturnTypeProviderEvent $event): \Psalm\Type\Union
    {
        $call_args = $event->getCallArgs();

        if ($call_args === []) {
            return new Union([
                new TNamedObject(ApplicationProvider::getAppFullyQualifiedClassName()),
            ]);
        }

        $statements_source = $event->getStatementsSource();

        return (
            ContainerResolver::resolvePsalmTypeFromApplicationContainerViaArgs(
                $statements_source->getNodeTypeProvider(),
                $call_args,
            ) ?? Type::getMixed()
        );
    }

    /** @inheritDoc */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [
            ApplicationProvider::getAppFullyQualifiedClassName(),
            // Container contracts: callers idiomatically type on the interface
            // (e.g. `$this->app` is the Application contract), so the make()
            // return must be narrowed for these receivers too — otherwise the
            // `@return mixed` on the contract stub (which hosts the CWE-470
            // taint sink) would surface as mixed at every `->make()` call site.
            \Illuminate\Contracts\Container\Container::class,
            \Illuminate\Contracts\Foundation\Application::class,
        ];
    }

    /**
     * Container methods carrying the `class-string<T> -> T` resolution contract: their return
     * IS the resolved instance, so narrowing it to the first `Foo::class` argument is correct.
     *
     * The gate matters because this provider is registered per-class (see getClassLikeNames),
     * so Psalm offers it EVERY method on the container — not just the resolving ones. Without
     * the name check the resolver rewrote the return of any container method whose first
     * argument is a class-string: `$app->when(Foo::class)` (a contextual-binding builder),
     * `$app->bound(Foo::class)` (a bool), `$app->instance(Foo::class, $x)` (the instance), etc.
     * all collapsed to `Foo`, and chains like `$app->when(Foo::class)->needs(...)` then reported
     * a false-positive `UndefinedMethod` (`Foo::needs`). Regression from #1075, which added the
     * container contracts to getClassLikeNames so make() narrows on `$this->app` too — widening
     * the set of receivers this un-gated provider fired for.
     *
     * Mirrors {@see \Psalm\LaravelPlugin\Handlers\Facades\AppFacadeMakeHandler::HANDLED_METHODS}
     * (the `App` facade analogue) — keep the two lists in sync.
     *
     * @var array<lowercase-string, true>
     */
    private const RESOLUTION_METHODS = [
        'make' => true,
        'makewith' => true,
        'get' => true,
    ];

    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Type\Union
    {
        if (!isset(self::RESOLUTION_METHODS[$event->getMethodNameLowercase()])) {
            return null;
        }

        return ContainerResolver::resolvePsalmTypeFromApplicationContainerViaArgs($event->getSource()->getNodeTypeProvider(), $event->getCallArgs());
    }

    /**
     * @see https://github.com/psalm/psalm-plugin-symfony/issues/25
     * psalm needs to know about any classes that could be returned before analysis begins. This is a naive first approach
     */
    #[\Override]
    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event): void
    {
        if (!\in_array($event->getStorage()->name, ApplicationInterfaceProvider::getApplicationInterfaceClassLikes(), true)) {
            return;
        }

        $bindings = \array_keys(ApplicationProvider::getApp()->getBindings());

        foreach ($bindings as $abstract) {
            try {
                if (!\is_string($abstract) && !\is_callable($abstract)) {
                    continue;
                }

                $concrete = ApplicationProvider::getApp()->make($abstract);

                if (!\is_object($concrete)) {
                    continue;
                }

                $reflectionClass = new \ReflectionClass($concrete);

                if ($reflectionClass->isAnonymous()) {
                    continue;
                }
            } catch (\Throwable) {
                // cannot just catch binding exception as the following error is emitted within laravel:
                // Class 'Symfony\Component\Cache\Adapter\Psr16Adapter' not found
                continue;
            }

            $className = $concrete::class;
            $filePath = $event->getStatementsSource()->getFilePath();
            $fileStorage = $event->getCodebase()->file_storage_provider->get($filePath);
            $fileStorage->referenced_classlikes[\strtolower($className)] = $className;
            $event->getCodebase()->queueClassLikeForScanning($className);
        }
    }
}
