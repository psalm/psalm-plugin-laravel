<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;

// A minimal real bootstrap/app.php (branch 1 of ApplicationProvider::doGetApp()), so the booted app
// binds 'blade.compiler' and a 'view.finder' anchored at THIS fixture's config/view.php paths.
$app = Application::configure(basePath: \dirname(__DIR__))->create();

// A package-style namespace hint outside vendor/, registered the way a local package's provider does.
$app->register(new class ($app) extends ServiceProvider {
    public function boot(): void
    {
        $this->loadViewsFrom(\dirname(__DIR__) . '/resources/pkg-views', 'pkg');
        // Written as a string: the plugin reads the composer's method from the analysed source, so the
        // class need not be loadable here.
        $this->app->make('view')->composer('edge-composed', 'AnnotateFixture\\EdgeComposer');
    }
});

return $app;
