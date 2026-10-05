<?php

declare(strict_types=1);

// So database_path('migrations') resolves here, not in Testbench's skeleton.
return Illuminate\Foundation\Application::configure(basePath: \dirname(__DIR__))->create();
