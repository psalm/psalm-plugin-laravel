<?php

declare(strict_types=1);

namespace GuardMethodResolutionFixture;

// #1113: every method here is reached only through `auth('web')` narrowing — the concrete
// `Illuminate\Auth\SessionGuard` class is never named, so nothing else forces Psalm to scan
// its real source. With the old full-class taint stub in place these calls all reported
// `UndefinedMethod` (the stub shadowed the class down to its single stubbed method). They must
// now resolve against the real Laravel class.
//
// `check`/`guest`/`id` come from the GuardHelpers trait; `attempt`/`logout`/`once`/`validate`/
// `loginUsingId` are declared on SessionGuard's body; `user` is the issue's headline case.
function exerciseSessionGuard(): void
{
    auth('web')->user();
    auth('web')->id();
    auth('web')->check();
    auth('web')->guest();
    auth('web')->attempt([]);
    auth('web')->logout();
    auth('web')->once([]);
    auth('web')->validate([]);
    auth('web')->loginUsingId(1);
}
