<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

return Application::configure(basePath: \dirname(__DIR__))
    // The shape of an app whose boot never binds the compiler: Blade has to disable itself.
    ->booted(static function (Application $app): void {
        unset($app['blade.compiler']);
    })
    ->create();
