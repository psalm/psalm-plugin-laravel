<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

// A minimal real bootstrap/app.php (branch 1 of ApplicationProvider::doGetApp()) — not the
// Testbench package-mode fallback (branch 3). Named routes only load through withRouting()
// on a real bootstrap boot; the Testbench branch never loads an application's route files at
// all (see ApplicationProvider::retargetConfigPathAtProjectRoot()'s docblock), so it can never
// populate this fixture's named-route table.
return Application::configure(basePath: \dirname(__DIR__))
    ->withRouting(web: __DIR__ . '/../routes/web.php')
    ->create();
