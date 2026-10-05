<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

// A real bootstrap, so database_path('migrations') resolves to this case's database/migrations: toArray()
// keys come from the schema.
return Application::configure(basePath: \dirname(__DIR__))->create();
