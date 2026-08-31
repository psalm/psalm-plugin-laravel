<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture;

use IndirectMethodReferencesFixture\Commands\ReferenceCommand;
use IndirectMethodReferencesFixture\Controllers\DriverController;
use IndirectMethodReferencesFixture\Controllers\ConcreteController;
use IndirectMethodReferencesFixture\Controllers\InvokableController;
use IndirectMethodReferencesFixture\Dependencies\ContractImplementation;
use IndirectMethodReferencesFixture\Dependencies\DynamicDependency;
use IndirectMethodReferencesFixture\Dependencies\ProtectedDependency;
use IndirectMethodReferencesFixture\Dependencies\UnusedDependency;
use IndirectMethodReferencesFixture\Dependencies\AbstractDependency;
use IndirectMethodReferencesFixture\Dependencies\DocblockOnlyDependency;
use IndirectMethodReferencesFixture\Models\User;

/** Keep fixture classes reachable while leaving their constructors/methods uncalled. */
function consume(): array
{
    return [
        DriverController::class,
        ConcreteController::class,
        InvokableController::class,
        ReferenceCommand::class,
        ContractImplementation::class,
        DynamicDependency::class,
        ProtectedDependency::class,
        UnusedDependency::class,
        AbstractDependency::class,
        DocblockOnlyDependency::class,
        User::class,
    ];
}
