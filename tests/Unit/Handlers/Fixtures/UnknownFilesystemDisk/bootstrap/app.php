<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

// A real bootstrap/app.php (ApplicationProvider boot mode 'bootstrap'): the Testbench fallback leaves
// UnknownFilesystemDisk disarmed, so only this boot reads the fixture's own config/filesystems.php.
return Application::configure(basePath: \dirname(__DIR__))->create();
