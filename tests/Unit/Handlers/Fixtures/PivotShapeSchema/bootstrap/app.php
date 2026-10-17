<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

// A minimal real bootstrap/app.php (branch 1 of ApplicationProvider::doGetApp()), see the
// UnknownModelAttribute fixture for why Testbench's package-mode fallback cannot be used.
return Application::configure(basePath: \dirname(__DIR__))->create();
