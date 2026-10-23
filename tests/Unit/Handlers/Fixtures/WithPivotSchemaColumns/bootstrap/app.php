<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

// A minimal real bootstrap/app.php: Testbench's package-mode fallback reads no project migrations.
return Application::configure(basePath: \dirname(__DIR__))->create();
