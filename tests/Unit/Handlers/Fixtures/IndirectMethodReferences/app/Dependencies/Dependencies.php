<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Dependencies;

final class UpdateDriver
{
    public function __construct()
    {
        \assert(true);
    }
}

final class InvokeDependency
{
    public function __construct()
    {
        \assert(\class_exists(self::class));
    }
}

final class CommandDependency
{
    public function __construct()
    {
        \assert(true);
    }
}

final class NestedDependency
{
    public function __construct()
    {
        \assert(\class_exists(self::class));
    }
}

final class OwnerDependency
{
    public function __construct(NestedDependency $dependency)
    {
        \assert(\is_object($dependency));
    }
}

final class CommandHelperDependency
{
    public function __construct()
    {
        \assert(\class_exists(self::class));
    }
}

final class HelperDependency
{
    public function __construct()
    {
        \assert(\class_exists(self::class));
    }
}

final class UnusedDependency
{
    public function __construct()
    {
        \assert(\class_exists(self::class));
    }
}

final class UnionDependencyA
{
    public function __construct()
    {
        \assert(\class_exists(self::class));
    }
}

final class UnionDependencyB
{
    public function __construct()
    {
        \assert(\class_exists(self::class));
    }
}

final class DynamicDependency
{
    public function __construct()
    {
        \assert(\class_exists(self::class));
    }
}

final class ProtectedDependency
{
    protected function __construct()
    {
        \assert(\class_exists(self::class));
    }
}

abstract class AbstractDependency
{
    public function __construct()
    {
        \assert(\class_exists(self::class));
    }
}

final class DocblockOnlyDependency
{
    public function __construct()
    {
        \assert(\class_exists(self::class));
    }
}

interface ContractDependency {}

final class ContractImplementation
{
    public function __construct()
    {
        \assert(\class_exists(self::class));
    }
}

final class InheritedActionDependency
{
    public function __construct()
    {
        \assert(\class_exists(self::class));
    }
}

final class TraitActionDependency
{
    public function __construct()
    {
        \assert(\class_exists(self::class));
    }
}

final class PublicControl
{
    public function discarded(): string
    {
        return self::class;
    }
}
