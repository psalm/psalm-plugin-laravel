<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Helpers;

use Closure;
use PhpParser\Node\Arg;
use PhpParser\Node\Scalar\String_;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\FunctionReturnTypeProviderInterface;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Union;

use function in_array;
use function is_string;

final class PathHandler implements FunctionReturnTypeProviderInterface, MethodReturnTypeProviderInterface
{
    /**
     * @inheritDoc
     * @see https://laravel.com/docs/master/helpers#paths
     */
    public static function getFunctionIds(): array
    {
        return [
            'app_path',
            'base_path',
            'config_path',
            'database_path',
            'lang_path',
            'resource_path',
            'public_path',
            'storage_path',
        ];
    }

    public static function getFunctionReturnType(FunctionReturnTypeProviderEvent $event): ?Union
    {
        $function_id = $event->getFunctionId();

        /**
         * @psalm-suppress MissingClosureReturnType
         */
        return self::resolveReturnType($event->getCallArgs(), function (array $args = []) use ($function_id) {
            return $function_id(...$args);
        });
    }

    public static function getClassLikeNames(): array
    {
        return [
            ApplicationProvider::getAppFullyQualifiedClassName(),
        ];
    }

    /** @inheritDoc */
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $methods = [
            'path',
            'basepath',
            'configpath',
            'databasepath',
            'langpath',
            'resourcepath',
            'publicpath',
            'storagepath',
        ];

        $method_name_lowercase = $event->getMethodNameLowercase();

        if (!in_array($method_name_lowercase, $methods, true)) {
            return null;
        }

        /**
         * @psalm-suppress MissingClosureReturnType
         */
        return self::resolveReturnType($event->getCallArgs(), static function (array $args) use ($method_name_lowercase) {
            return ApplicationProvider::getApp()->{$method_name_lowercase}(...$args);
        });
    }

    /**
     * @param list<\PhpParser\Node\Arg> $call_args
     * @param \Closure(list<string>=): mixed $closure
     */
    private static function resolveReturnType(array $call_args, Closure $closure): Union
    {
        // we're going to do some dynamic analysis here. Were going to invoke the closure that is wrapping the
        // app method or the global function in order to determine the literal string path that is returned
        // so that we can inform psalm of where the files live.
        $argument = '';

        $first_argument = $call_args[0] ?? null;
        if ($first_argument instanceof Arg) {
            $argumentType = $first_argument->value;
            if ($argumentType instanceof String_) {
                $argument = $argumentType->value;
            }
        }

        $result = $closure([$argument]);

        if (!$result || !is_string($result)) {
            return new Union([
                new TString(),
            ]);
        }

        return new Union([
            new TLiteralString($result),
        ]);
    }
}
