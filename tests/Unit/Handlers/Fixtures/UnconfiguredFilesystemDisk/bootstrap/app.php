<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

// A real bootstrap/app.php (not the Testbench fallback): only this boot path reads this
// directory's config/filesystems.php, which is what arms the rule with the fixture's disks.
return Application::configure(basePath: \dirname(__DIR__))->create();
