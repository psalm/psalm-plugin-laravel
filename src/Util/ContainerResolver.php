<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Util;

use PhpParser\Node\Arg;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;
use Psalm\NodeTypeProvider;
use Psalm\Type\Atomic\TClassString;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TTemplateParamClass;
use Psalm\Type\Union;

final class ContainerResolver
{
    /**
     * map of abstract to concrete class fqn
     * @psalm-var array<string, class-string|string>
     */
    private static array $cache = [];

    /**
     * @psalm-return class-string|string|null
     */
    private static function resolveFromApplicationContainer(string $abstract): ?string
    {
        if (\array_key_exists($abstract, self::$cache)) {
            return self::$cache[$abstract];
        }

        // dynamic analysis to resolve the actual type from the container
        try {
            $concrete = ApplicationProvider::getApp()->make($abstract);
            assert(\is_object($concrete) || \is_string($concrete));
        } catch (\Throwable) {
            return null;
        }

        if (\is_string($concrete)) {
            // some path-helpers actually return a string when being resolved
            $concreteClass = $concrete;
        } else {
            // normally we have an object resolved
            $concreteClass = $concrete::class;
        }

        self::$cache[$abstract] = $concreteClass;

        return $concreteClass;
    }

    /**
     * @param list<Arg> $call_args
     */
    public static function resolvePsalmTypeFromApplicationContainerViaArgs(NodeTypeProvider $nodeTypeProvider, array $call_args): ?Union
    {
        if ($call_args === []) {
            return null;
        }

        $firstArgType = $nodeTypeProvider->getType($call_args[0]->value);
        if ($firstArgType === null) {
            return null;
        }

        if ($firstArgType->isSingleStringLiteral()) {
            return self::resolveFromLiteralString($firstArgType->getSingleStringLiteral()->value);
        }

        if (!$firstArgType->isSingle()) {
            return null;
        }

        $atomic = $firstArgType->getSingleAtomic();
        if ($atomic instanceof TClassString) {
            return self::resolveFromClassString($atomic);
        }

        return null;
    }

    private static function resolveFromLiteralString(string $abstract): ?Union
    {
        $concrete = self::resolveFromApplicationContainer($abstract);

        if ($concrete === null) {
            return null;
        }

        // todo: is there a better way to check if this is a literal class string?
        if (\class_exists($concrete)) {
            return new Union([
                new TNamedObject($concrete),
            ]);
        }

        // the likes of publicPath, which returns a literal string
        return new Union([
            TLiteralString::make($concrete),
        ]);
    }

    /**
     * Resolves `app($classString)` / `resolve($classString)` / `make($classString)` where
     * `$classString` is typed as a `class-string<Foo>` atomic rather than a literal.
     *
     * This covers both `static::class` (Psalm encodes it as `TClassString($fq_class_name,
     * new TNamedObject($fq_class_name, is_static: true))`, see ClassConstAnalyzer) and
     * variables typed as `class-string<Foo>`.
     *
     * @psalm-pure
     */
    private static function resolveFromClassString(TClassString $atomic): ?Union
    {
        $asType = $atomic->as_type;
        if ($asType === null) {
            // Bare `class-string` (no constraint). We cannot narrow further.
            return null;
        }

        if ($atomic instanceof TTemplateParamClass) {
            // `class-string<T>` template parameter. Resolving to the upper bound would
            // mask template tracking at the call site (a correctly-T-returning statement
            // would become InvalidReturnStatement), so falling back to mixed is
            // conservative until the plugin can project T into the return type.
            return null;
        }

        // For `static::class`, $asType already carries `is_static: true` and renders as
        // `Foo&static`, preserving late static binding through callers.
        return new Union([$asType]);
    }
}
