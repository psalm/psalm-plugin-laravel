<?php

declare(strict_types=1);

use Fx\Demo\Providers\DemoServiceProvider;
use Fx\Providers\ThrowingDirectiveProvider;
use Illuminate\Foundation\Application;

// Nothing autoloads `app/Providers` or `packages/`, which is the point for the Demo provider: it is
// reachable only because this file requires it, as a package-style monorepo's root bootstrap does.
// The directive provider's boot() runs as real PHP during the app boot below.
require_once __DIR__ . '/../app/Providers/ThrowingDirectiveProvider.php';
require_once __DIR__ . '/../packages/Demo/src/Providers/DemoServiceProvider.php';
// Declared in-process, so the booted app knows this class the way it knows any project class Composer
// can autoload: `resources/views/class-collision-project.blade.php` redeclares it (#2126).
require_once __DIR__ . '/../app/CollidedReport.php';

return Application::configure(basePath: \dirname(__DIR__))
    ->withProviders([ThrowingDirectiveProvider::class, DemoServiceProvider::class])
    ->create();
