<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

// A minimal real bootstrap/app.php (branch 1 of ApplicationProvider::doGetApp()), so the booted app
// binds 'blade.compiler' and a 'view.finder' anchored at THIS fixture's config/view.php paths.
$app = Application::configure(basePath: \dirname(__DIR__))->create();

// A view composer, as an application's service provider would register one: the `composed` view's
// data is then not what its call sites pass.
$app->booted(static function () use ($app): void {
    $view = $app->make('view');
    $view->composer('composed', static fn(): null => null);

    // Class composers whose bodies the plugin reads: see app/ComposerCallSites.php.
    $composers = 'ViewContractFixture\\Composers\\';
    $view->composer('composer-literal', $composers . 'LiteralComposer');
    $view->composer('composer-dynamic', $composers . 'DynamicKeyComposer');
    $view->composer('composer-escape', $composers . 'EscapingComposer');
    $view->composer('composer-wild.*', $composers . 'WildcardComposer');
    $view->composer('composer-wild.page', $composers . 'MethodComposer@build');
    $view->composer('composer-inherited', $composers . 'InheritedComposer');
    $view->composer('composer-conflict', $composers . 'ConflictComposer');
    $view->composer('composer-alias', $composers . 'AliasConflictComposer@build');
    $view->composer('composer-ctor', $composers . 'ConstructorShareComposer');
    $view->composer('composer-ctor-plain', $composers . 'PromotedConstructorComposer');
    $view->composer('composer-parent-ctor', $composers . 'ParentConstructorComposer');
    $view->composer('composer-inherited-ctor', $composers . 'InheritedConstructorComposer');
    $view->composer('composer-protected', $composers . 'ProtectedComposer');
    $view->composer('composer-protected-trait', $composers . 'ProtectedTraitComposer');
    $view->composer('composer-ctor-default', $composers . 'ConstructorDefaultComposer');
    // A numeric view name is an integer array key once it has been through a map.
    $view->composer('123', $composers . 'LiteralComposer');
    $view->creator('composer-created', $composers . 'CreatorComposer');
    $view->composer('composer-unknown', $composers . 'LiteralComposer');
    $view->composer('composer-unknown', static fn(): null => null);
});

return $app;
