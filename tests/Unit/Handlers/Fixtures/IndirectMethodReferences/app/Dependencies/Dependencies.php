<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Dependencies;

final class UpdateDriver {}

final class InvokeDependency {}

final class CommandDependency {}

final class NestedDependency {}

final class OwnerDependency {}

final class HelperDependency {}

final class UnusedDependency {}

final class UnionDependencyA {}

final class UnionDependencyB {}

final class ProtectedDependency {}

abstract class AbstractDependency {}

final class DocblockOnlyDependency {}

interface ContractDependency {}

final class ContractImplementation implements ContractDependency {}

final class PublicControl
{
    public function discarded(): string
    {
        return self::class;
    }
}
