<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

// A minimal real bootstrap/app.php (branch 1 of ApplicationProvider::doGetApp()), so the booted app
// binds 'blade.compiler' and a 'view.finder' anchored at THIS fixture's config/view.php paths.
$app = Application::configure(basePath: \dirname(__DIR__))->create();

// Boot-time view data, as a service provider registers it: a shared key and a composer.
$app->booted(static function () use ($app): void {
    $view = $app->make('view');
    $view->share('siteName', 'Fixture');
    $view->composer('composed', static fn(): null => null);
});

return $app;
