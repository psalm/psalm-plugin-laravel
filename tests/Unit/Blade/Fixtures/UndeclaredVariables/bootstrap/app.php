<?php

declare(strict_types=1);

use App\Providers\ViewDataProvider;
use Illuminate\Foundation\Application;

return Application::configure(basePath: \dirname(__DIR__))
    ->withProviders([ViewDataProvider::class])
    ->create();
