<?php

declare(strict_types=1);

use BladeCompileErrorFixture\Providers\ThrowingDirectiveProvider;
use Illuminate\Foundation\Application;

// Required directly: the fixture has no composer autoload mapping, but the provider's boot() runs
// as real PHP during the app boot below.
require_once __DIR__ . '/../app/Providers/ThrowingDirectiveProvider.php';

return Application::configure(basePath: \dirname(__DIR__))
    ->withProviders([ThrowingDirectiveProvider::class])
    ->create();
