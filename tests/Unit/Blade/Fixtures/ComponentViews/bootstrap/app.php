<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;

// A minimal real bootstrap/app.php (branch 1 of ApplicationProvider::doGetApp()), so the booted app
// binds 'blade.compiler' and a 'view.finder' anchored at THIS fixture's config/view.php paths.
$app = Application::configure(basePath: \dirname(__DIR__))->create();

// A package-style namespace hint, registered the way a local package's provider does.
$app->register(new class ($app) extends ServiceProvider {
    public function boot(): void
    {
        $this->loadViewsFrom(\dirname(__DIR__) . '/resources/pkg-views', 'pkg');
    }
});

// A view composer, as an application's service provider would register one.
$app->booted(static function () use ($app): void {
    $app->make('view')->composer('components.composed', \App\View\Composers\LabelComposer::class);
});

return $app;
