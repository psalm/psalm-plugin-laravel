<?php

declare(strict_types=1);

// Standalone autoloader for the fixture models. The pivot shape resolver reads the ModelMetadataRegistry
// (custom pivot class, model passed as the table), which ModelRegistrationHandler warms by reflecting on
// each model via class_exists($name, autoload: true), so the fixture classes MUST be loadable in the Psalm
// subprocess. A dedicated, non-Composer namespace keeps the fixture isolated from the package autoloader.
\spl_autoload_register(static function (string $class): void {
    $prefix = 'PivotShapeFixture\\';

    if (!\str_starts_with($class, $prefix)) {
        return;
    }

    $relative = \str_replace('\\', '/', \substr($class, \strlen($prefix)));
    $file = __DIR__ . '/app/' . $relative . '.php';

    if (\is_file($file)) {
        require_once $file;
    }
});
