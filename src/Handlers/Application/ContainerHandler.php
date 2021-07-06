<?php

namespace Psalm\LaravelPlugin\Handlers\Application;

use PhpParser\Node\Arg;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Providers\ApplicationInterfaceProvider;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;
use Psalm\LaravelPlugin\Util\ContainerResolver;
use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use Psalm\Plugin\Hook\FunctionReturnTypeProviderInterface;
use Psalm\Plugin\Hook\MethodReturnTypeProviderInterface;
use Psalm\StatementsSource;
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

/**
 * @psalm-suppress DeprecatedInterface
 */
final class ContainerHandler implements AfterClassLikeVisitInterface, FunctionReturnTypeProviderInterface, MethodReturnTypeProviderInterface
{
    /**
     * @return array<array-key, lowercase-string>
     */
    public static function getFunctionIds(): array
    {
        return ['app', 'resolve'];
    }

    /**
     * @param  array<Arg> $call_args
     */
    public static function getFunctionReturnType(StatementsSource $statements_source, string $function_id, array $call_args, Context $context, CodeLocation $code_location): ?Union
    {
        if (!$call_args) {
            return new Union([
                new TNamedObject(get_class(ApplicationProvider::getApp())),
            ]);
        }

        return ContainerResolver::resolvePsalmTypeFromApplicationContainerViaArgs($statements_source->getNodeTypeProvider(), $call_args) ?? Type::getMixed();
    }

    public static function getClassLikeNames(): array
    {
        return [get_class(ApplicationProvider::getApp())];
    }

    public static function getMethodReturnType(
        StatementsSource $source,
        string $fq_classlike_name,
        string $method_name_lowercase,
        array $call_args,
        Context $context,
        CodeLocation $code_location,
        ?array $template_type_parameters = null,
        ?string $called_fq_classlike_name = null,
        ?string $called_method_name_lowercase = null
    ) : ?Type\Union {
        // lumen doesn't have the likes of makeWith, so we will ensure these methods actually exist on the underlying
        // app contract
        $methods = array_filter(['make', 'makewith'], function (string $methodName) use ($source, $fq_classlike_name) {
            $methodId = new MethodIdentifier($fq_classlike_name, $methodName);
            return $source->getCodebase()->methodExists($methodId);
        });

        if (!in_array($method_name_lowercase, $methods)) {
            return null;
        }

        return ContainerResolver::resolvePsalmTypeFromApplicationContainerViaArgs($source->getNodeTypeProvider(), $call_args);
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
