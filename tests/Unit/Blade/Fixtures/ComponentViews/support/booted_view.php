<?php

declare(strict_types=1);

namespace App\View\Components\Booted;

use Illuminate\Contracts\View\View;

/**
 * Outside `app/` (not a project file), loaded only by the fixture's service provider at boot: the
 * way an app's `require`d helper file declares a function Psalm would never scan on its own.
 */
function view(string $name): View
{
    return \view($name, ['code' => 1]);
}
