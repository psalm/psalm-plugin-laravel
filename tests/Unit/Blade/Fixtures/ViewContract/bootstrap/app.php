<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

// A minimal real bootstrap/app.php (branch 1 of ApplicationProvider::doGetApp()), so the booted app
// binds 'blade.compiler' and a 'view.finder' anchored at THIS fixture's config/view.php paths.
require_once __DIR__ . '/../app/IncludingComponent.php';

$app = Application::configure(basePath: \dirname(__DIR__))->create();

// A view composer, as an application's service provider would register one: the `composed` view's
// data is then not what its call sites pass.
$app->booted(static function () use ($app): void {
    $app->make('view')->composer('composed', static fn(): null => null);
    \Illuminate\Support\Facades\Blade::component('including-component', \ViewContractFixture\IncludingComponent::class);
});

return $app;
