<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\Octane;

use Illuminate\Container\Container;
use Illuminate\Http\Request;

final class UserContainerSubclassService {}

/** Inherits `singleton()` but may give `scoped()` other parameters, so only Laravel's own classes are renamed. */
final class UserContainerSubclassContainer extends Container {}

final class UserContainerSubclass
{
    public function register(UserContainerSubclassContainer $app): void
    {
        $app->singleton(UserContainerSubclassService::class, fn($c) => new UserContainerSubclassService($c->make(Request::class)));
    }
}
