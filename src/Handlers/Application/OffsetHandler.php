<?php

namespace Psalm\LaravelPlugin\Handlers\Application;

use Psalm\LaravelPlugin\Providers\ApplicationInterfaceProvider;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;
use Psalm\LaravelPlugin\Util\ContainerResolver;
use Psalm\Plugin\EventHandler\Event\MethodExistenceProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodVisibilityProviderEvent;
use Psalm\Plugin\EventHandler\MethodExistenceProviderInterface;
use Psalm\Plugin\EventHandler\MethodParamsProviderInterface;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Plugin\EventHandler\MethodVisibilityProviderInterface;
use Psalm\Type;

use function in_array;

final class OffsetHandler implements
    MethodReturnTypeProviderInterface,
    MethodExistenceProviderInterface,
    MethodVisibilityProviderInterface,
    MethodParamsProviderInterface
{
    /** @return list<class-string> */
    public static function getClassLikeNames(): array
    {
        return ApplicationInterfaceProvider::getApplicationInterfaceClassLikes();
    }

    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Type\Union
    {
        $method_name_lowercase = $event->getMethodNameLowercase();
        $source = $event->getSource();

        if ($method_name_lowercase === 'offsetget') {
            // offsetget is an alias for make
            return ContainerResolver::resolvePsalmTypeFromApplicationContainerViaArgs(
                $source->getNodeTypeProvider(),
                $event->getCallArgs()
            );
        }

        if ($method_name_lowercase === 'offsetset') {
            $fq_classlike_name = $event->getFqClasslikeName();
            return $source->getCodebase()->getMethodReturnType(
                ApplicationProvider::getAppFullyQualifiedClassName() . '::' . $method_name_lowercase,
                $fq_classlike_name
            );
        }

        return null;
    }

    public static function doesMethodExist(MethodExistenceProviderEvent $event): ?bool
    {
        return self::isOffsetMethod($event->getMethodNameLowercase()) ? true : null;
    }

    public static function isMethodVisible(MethodVisibilityProviderEvent $event): ?bool
    {
        return self::isOffsetMethod($event->getMethodNameLowercase()) ? true : null;
    }

    public static function getMethodParams(MethodParamsProviderEvent $event): ?array
    {
        $source = $event->getStatementsSource();
        if (!$source) {
            return null;
        }

        if (!self::isOffsetMethod($event->getMethodNameLowercase())) {
            return null;
        }

        return $source->getCodebase()->getMethodParams(
            ApplicationProvider::getAppFullyQualifiedClassName() . '::' . $event->getMethodNameLowercase()
        );
    }

    private static function isOffsetMethod(string $methodName): bool
    {
        return in_array($methodName, [
            'offsetget',
            'offsetset',
        ], true);
    }
}
