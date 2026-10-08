<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture;

use IndirectMethodReferencesFixture\Commands\ReferenceCommand;
use IndirectMethodReferencesFixture\Controllers\ConcreteController;
use IndirectMethodReferencesFixture\Controllers\DriverController;
use IndirectMethodReferencesFixture\Controllers\InvocableController;
use IndirectMethodReferencesFixture\Conventions\AddHeaderMiddleware;
use IndirectMethodReferencesFixture\Conventions\DemotedPipe;
use IndirectMethodReferencesFixture\Conventions\DispatchedJob;
use IndirectMethodReferencesFixture\Conventions\PlainHandleClass;
use IndirectMethodReferencesFixture\Conventions\PlainInvokable;
use IndirectMethodReferencesFixture\Conventions\PrivateInvokable;
use IndirectMethodReferencesFixture\Conventions\PromotedPipe;
use IndirectMethodReferencesFixture\Conventions\SendReportJob;
use IndirectMethodReferencesFixture\Conventions\UntypedNextMiddleware;
use IndirectMethodReferencesFixture\Dependencies\AbstractDependency;
use IndirectMethodReferencesFixture\Dependencies\ContractImplementation;
use IndirectMethodReferencesFixture\Dependencies\DocblockOnlyDependency;
use IndirectMethodReferencesFixture\Dependencies\ProtectedDependency;
use IndirectMethodReferencesFixture\Dependencies\PublicControl;
use IndirectMethodReferencesFixture\Dependencies\UnusedDependency;
use IndirectMethodReferencesFixture\Models\User;

/** Keep fixture classes reachable while leaving their constructors/methods uncalled. */
function consume(): array
{
    // Discards a public, non-entrypoint method's return: must stay reportable after the fix.
    (new PublicControl())->discarded();

    return [
        DriverController::class,
        ConcreteController::class,
        InvocableController::class,
        ReferenceCommand::class,
        ContractImplementation::class,
        ProtectedDependency::class,
        UnusedDependency::class,
        AbstractDependency::class,
        DocblockOnlyDependency::class,
        User::class,
        PlainInvokable::class,
        AddHeaderMiddleware::class,
        UntypedNextMiddleware::class,
        PlainHandleClass::class,
        PrivateInvokable::class,
        DemotedPipe::class,
        PromotedPipe::class,
        new SendReportJob('report', 'note'),
        DispatchedJob::dispatch('payload'),
    ];
}
