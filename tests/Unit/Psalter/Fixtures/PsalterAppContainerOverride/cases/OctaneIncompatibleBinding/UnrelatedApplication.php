<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\Octane;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

final class UnrelatedApplicationService {}

/**
 * The overriding container of BaseTypedContainer does not descend from Application, so a call typed as the
 * application is still renamed.
 */
final class UnrelatedApplication
{
    public function register(Application $app): void
    {
        $app->singleton(abstract: UnrelatedApplicationService::class, concrete: fn(Application $c) => new UnrelatedApplicationService($c->make(Request::class)));
    }
}
