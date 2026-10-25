<?php

declare(strict_types=1);

// One private-namespace autoloader per scenario directory under app/. The plugin warms the model
// registry by reflecting on each model via class_exists($name, autoload: true), so the fixture
// classes MUST be loadable in the Psalm subprocess; keeping them out of the package autoloader
// isolates them from it. Psalm's scanner only parses the AST and never `require`s a file, so a class
// stays unloaded until something autoloads it (UndefinedRelationAutoloadCrash relies on that).
const EMISSION_APP_NAMESPACES = [
    'KnownAttributeFixture\\' => 'UnknownModelAttribute',
    'RelationDefaultsFixture\\' => 'UndefinedModelRelationDefaults',
    'AutoloadCrashFixture\\' => 'UndefinedRelationAutoloadCrash',
    'ScopeUnusedCodeFixture\\' => 'SuppressScopeUnusedCode',
    'DynamicWhereFixture\\' => 'DynamicWhereSchemaColumns',
    'AppendsFixture\\' => 'UnresolvableAppendedModelAttribute',
    'UnregisteredRouteNameFixture\\' => 'UnregisteredRouteName',
];

\spl_autoload_register(static function (string $class): void {
    foreach (EMISSION_APP_NAMESPACES as $prefix => $directory) {
        if (!\str_starts_with($class, $prefix)) {
            continue;
        }

        $file = __DIR__ . '/app/' . $directory . '/' . \str_replace('\\', '/', \substr($class, \strlen($prefix))) . '.php';

        if (\is_file($file)) {
            require_once $file;
        }

        return;
    }
});
