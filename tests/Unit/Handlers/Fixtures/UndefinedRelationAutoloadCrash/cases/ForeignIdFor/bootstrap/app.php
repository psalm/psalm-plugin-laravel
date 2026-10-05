<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

// A real bootstrap, so database_path('migrations') resolves to this case's database/migrations
// (the Testbench fallback anchors it at its own skeleton).
return Application::configure(basePath: \dirname(__DIR__))->create();
