<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

// A minimal real bootstrap/app.php (branch 1 of ApplicationProvider::doGetApp()), so the booted app
// binds 'blade.compiler' and a 'view.finder' anchored at THIS fixture's config/view.php paths.
return Application::configure(basePath: \dirname(__DIR__))->create();
