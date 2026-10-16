<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

// A minimal real bootstrap/app.php (branch 1 of ApplicationProvider::doGetApp()) — not the Testbench
// package-mode fallback (branch 3). database_path('migrations') only resolves to THIS directory's
// database/migrations under a real bootstrap boot; the Testbench branch anchors database_path() at
// its bundled skeleton regardless of the analysed project (see
// ApplicationProvider::retargetConfigPathAtProjectRoot()'s docblock), so it can never see this
// fixture's migration.
return Application::configure(basePath: \dirname(__DIR__))->create();
