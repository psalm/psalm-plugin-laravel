<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Controllers;

use IndirectMethodReferencesFixture\Dependencies\ContractDependency;
use IndirectMethodReferencesFixture\Dependencies\AbstractDependency;
use IndirectMethodReferencesFixture\Dependencies\DocblockOnlyDependency;
use IndirectMethodReferencesFixture\Dependencies\HelperDependency;
use IndirectMethodReferencesFixture\Dependencies\ProtectedDependency;
use IndirectMethodReferencesFixture\Dependencies\UnionDependencyA;
use IndirectMethodReferencesFixture\Dependencies\UnionDependencyB;
use IndirectMethodReferencesFixture\Dependencies\UpdateDriver;

final class DriverController extends BaseController
{
    use ActionTrait;

    public function update(UpdateDriver $updateDriver): void {}

    public function contract(ContractDependency $dependency): void {}

    public function union(UnionDependencyA|UnionDependencyB $dependency): void {}

    public function dynamic(mixed $dependency): void {}

    public function inaccessible(ProtectedDependency $dependency): void {}

    public function abstractDependency(AbstractDependency $dependency): void {}

    /** @param DocblockOnlyDependency $dependency */
    public function docblockOnly($dependency): void {}

    /** A helper is not an entrypoint and must not make its dependency look used. */
    private function helper(HelperDependency $dependency): void {}
}
