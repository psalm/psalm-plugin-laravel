<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Dependencies;

final class UpdateDriver
{
    public function __construct() { \assert(true); }
}

final class InvokeDependency
{
    public function __construct() {}
}

final class CommandDependency
{
    public function __construct() { \assert(true); }
}

final class NestedDependency
{
    public function __construct() {}
}

final class OwnerDependency
{
    public function __construct(NestedDependency $dependency) {}
}

final class CommandHelperDependency
{
    public function __construct() {}
}

final class HelperDependency
{
    public function __construct() {}
}

final class UnusedDependency
{
    public function __construct() {}
}

final class UnionDependencyA
{
    public function __construct() {}
}

final class UnionDependencyB
{
    public function __construct() {}
}

final class DynamicDependency
{
    public function __construct() {}
}

final class ProtectedDependency
{
    protected function __construct() {}
}

abstract class AbstractDependency
{
    public function __construct() {}
}

final class DocblockOnlyDependency
{
    public function __construct() {}
}

interface ContractDependency {}

final class ContractImplementation
{
    public function __construct() {}
}

final class InheritedActionDependency
{
    public function __construct() {}
}

final class TraitActionDependency
{
    public function __construct() {}
}
