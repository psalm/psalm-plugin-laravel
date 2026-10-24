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
        // Markdown mail swaps `mail` for its component paths at render time, so this hint never
        // renders behind a markdown() call.
        $this->loadViewsFrom(\dirname(__DIR__) . '/resources/mail-views', 'mail');
    }
});

// A view composer, as an application's service provider would register one: the `composed` view's
// data is then not what its call sites pass.
$app->booted(static function () use ($app): void {
    $view = $app->make('view');
    $view->composer('composed', static fn(): null => null);

    // Class composers whose bodies the plugin reads: see app/ComposerCallSites.php.
    $composers = 'ViewContractFixture\\Composers\\';
    $view->composer('composer-literal', $composers . 'LiteralComposer');
    $view->composer('composer-wild.*', $composers . 'LiteralComposer');
    $view->composer('composer-wild.page', $composers . 'MethodComposer@build');
    $view->creator('composer-created', $composers . 'MethodComposer@build');
    $view->composer('composer-inherited', $composers . 'InheritedComposer');
    $view->composer('composer-accessor', $composers . 'AccessorComposer');
    $view->composer('composer-dynamic', $composers . 'LiteralComposer');
    $view->composer('composer-dynamic', $composers . 'MethodComposer@dynamic');
    $view->composer('composer-compact', $composers . 'MethodComposer@compacted');

    // Observers registered straight on the dispatcher, as Ray, Telescope or Debugbar do: they reach
    // every view and supply nothing.
    $events = $app->make('events');
    $events->listen('composing:*', static fn(): null => null);
    $events->listen('*', static fn(): null => null);
});

return $app;
