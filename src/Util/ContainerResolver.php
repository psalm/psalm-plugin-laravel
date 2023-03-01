<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Util;

use PhpParser\Node\Arg;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;
use Psalm\NodeTypeProvider;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

use function array_key_exists;
use function class_exists;
use function count;
use function get_class;
use function is_null;
use function is_object;
use function is_string;

final class ContainerResolver
{
    /**
     * map of abstract to concrete class fqn
     * @var array
     * @psalm-var array<string, class-string|string>
     */
    private static $cache = [];

    /**
     * @psalm-return class-string|string|null
     */
    private static function resolveFromApplicationContainer(string $abstract): ?string
    {
        if (array_key_exists($abstract, static::$cache)) {
            return static::$cache[$abstract];
        }

        // dynamic analysis to resolve the actual type from the container
        try {
            /** @var mixed $concrete */
            $concrete = ApplicationProvider::getApp()->make($abstract);
        } catch (\Throwable $e) {
            return null;
        }

        if (is_string($concrete)) {
            // some path-helpers actually return a string when being resolved
            $concreteClass = $concrete;
        } elseif (is_object($concrete)) {
            // normally we have an object resolved
            $concreteClass = get_class($concrete);
        } else {
            // not sure how to handle this yet
            return null;
        }

        static::$cache[$abstract] = $concreteClass;

        return $concreteClass;
    }

    /**
     * @param list<Arg> $call_args
     */
    public static function resolvePsalmTypeFromApplicationContainerViaArgs(NodeTypeProvider $nodeTypeProvider, array $call_args): ?Union
    {
        if (! count($call_args)) {
            return null;
        }

        $firstArgType = $nodeTypeProvider->getType($call_args[0]->value);

        if ($firstArgType && $firstArgType->isSingleStringLiteral()) {
            $abstract = $firstArgType->getSingleStringLiteral()->value;
            $concrete = self::resolveFromApplicationContainer($abstract);

            if (is_null($concrete)) {
                return null;
            }

            // todo: is there a better way to check if this is a literal class string?
            if (class_exists($concrete)) {
                return new Union([
                    new TNamedObject($concrete),
                ]);
            }

            // the likes of publicPath, which returns a literal string
            return new Union([
                new TLiteralString($concrete),
            ]);
        }

        return null;
    }
}
