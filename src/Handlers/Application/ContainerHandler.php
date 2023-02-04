<?php

namespace Psalm\LaravelPlugin\Handlers\Application;

use Psalm\Internal\MethodIdentifier;
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
use ReflectionClass;
use Throwable;

use function array_filter;
use function array_keys;
use function get_class;
use function in_array;
use function is_object;
use function strtolower;
use function is_string;
use function is_callable;

final class ContainerHandler implements AfterClassLikeVisitInterface, FunctionReturnTypeProviderInterface, MethodReturnTypeProviderInterface
{
    /** @inheritDoc */
    public static function getFunctionIds(): array
    {
        return ['app', 'resolve'];
    }

    /** @inheritDoc */
    public static function getFunctionReturnType(FunctionReturnTypeProviderEvent $event): ?Union
    {
        $call_args = $event->getCallArgs();

        if ($call_args === []) {
            return new Union([
                new TNamedObject(ApplicationProvider::getAppFullyQualifiedClassName()),
            ]);
        }

        $statements_source = $event->getStatementsSource();

        return ContainerResolver::resolvePsalmTypeFromApplicationContainerViaArgs($statements_source->getNodeTypeProvider(), $call_args) ?? Type::getMixed();
    }

    /** @inheritDoc */
    public static function getClassLikeNames(): array
    {
        return [ApplicationProvider::getAppFullyQualifiedClassName()];
    }

    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Type\Union
    {
        // lumen doesn't have the likes of makeWith, so we will ensure these methods actually exist on the underlying
        // app contract
        $methods = array_filter(['make', 'makewith'], static function (string $methodName) use ($event) {
            $methodId = new MethodIdentifier($event->getFqClasslikeName(), $methodName);
            return $event->getSource()->getCodebase()->methodExists($methodId);
        });

        if (!in_array($event->getMethodNameLowercase(), $methods, true)) {
            return null;
        }

        return ContainerResolver::resolvePsalmTypeFromApplicationContainerViaArgs($event->getSource()->getNodeTypeProvider(), $event->getCallArgs());
    }

    /**
     * @see https://github.com/psalm/psalm-plugin-symfony/issues/25
     * psalm needs to know about any classes that could be returned before analysis begins. This is a naive first approach
     */
    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event)
    {
        if (!in_array($event->getStorage()->name, ApplicationInterfaceProvider::getApplicationInterfaceClassLikes())) {
            return;
        }

        $bindings = array_keys(ApplicationProvider::getApp()->getBindings());

        foreach ($bindings as $abstract) {
            try {
                if (!is_string($abstract) && !is_callable($abstract)) {
                    continue;
                }

                /** @psalm-suppress MixedArgument */
                $concrete = ApplicationProvider::getApp()->make($abstract);

                if (!is_object($concrete)) {
                    continue;
                }

                $reflectionClass = new ReflectionClass($concrete);

                if ($reflectionClass->isAnonymous()) {
                    continue;
                }
            } catch (Throwable $e) {
                // cannot just catch binding exception as the following error is emitted within laravel:
                // Class 'Symfony\Component\Cache\Adapter\Psr16Adapter' not found
                continue;
            }

            $className = get_class($concrete);
            $filePath = $event->getStatementsSource()->getFilePath();
            $fileStorage = $event->getCodebase()->file_storage_provider->get($filePath);
            $fileStorage->referenced_classlikes[strtolower($className)] = $className;
            $event->getCodebase()->queueClassLikeForScanning($className);
        }
    }
}
