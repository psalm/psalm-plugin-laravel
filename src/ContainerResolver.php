<?php declare(strict_types=1);

namespace Psalm\LaravelPlugin;

use Illuminate\Contracts\Container\BindingResolutionException;
use Psalm\NodeTypeProvider;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;
use ReflectionException;
use function array_key_exists;
use function get_class;
use function count;

final class ContainerResolver
{
    /**
     * map of abstract to concrete class fqn
     * @var array
     * @psalm-var array<string, class-string>
     */
    private static $cache = [];

    /**
     * @psalm-suppress MoreSpecificReturnType
     * @psalm-suppress PropertyTypeCoercion
     * @psalm-suppress LessSpecificReturnStatement
     * @see https://github.com/vimeo/psalm/issues/3894
     * @psalm-return class-string|null
     */
    public static function resolveFromApplicationContainer(string $abstract): ?string
    {
        if (array_key_exists($abstract, static::$cache)) {
            return static::$cache[$abstract];
        }

        // dynamic analysis to resolve the actual type from the container
        try {
            $concrete = ApplicationHelper::getApp()->make($abstract);
        } catch (BindingResolutionException | ReflectionException $e) {
            return null;
        }

        $concreteClass = get_class($concrete);

        if (!$concreteClass) {
            return null;
        }

        static::$cache[$abstract] = $concreteClass;

        return $concreteClass;
    }

    /**
     * @param array<\PhpParser\Node\Arg> $call_args
     */
    public static function resolvePsalmTypeFromApplicationContainerViaArgs(NodeTypeProvider $nodeTypeProvider, array $call_args): ?Union
    {
        if (! count($call_args)) {
            return null;
        }

        $firstArgType = $nodeTypeProvider->getType($call_args[0]->value);

        if ($firstArgType && $firstArgType->isSingleStringLiteral()) {
            $abstract = $firstArgType->getSingleStringLiteral()->value;
            $concreteClass = static::resolveFromApplicationContainer($abstract);
            if ($concreteClass) {
                return new Union([
                    new TNamedObject($concreteClass),
                ]);
            }
        }

        return null;
    }
}
