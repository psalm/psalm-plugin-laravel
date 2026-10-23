<?php

declare(strict_types=1);

// Standalone autoloader for the fixture models. ModelRegistrationHandler registers the relation
// return-type closure only for models it can load (class_exists($name, autoload: true)), so the
// fixture classes MUST be loadable in the Psalm subprocess. A dedicated, non-Composer namespace
// keeps the fixture isolated from the package autoloader.
\spl_autoload_register(static function (string $class): void {
    $prefix = 'WithPivotFixture\\';

    if (!\str_starts_with($class, $prefix)) {
        return;
    }

    $relative = \str_replace('\\', '/', \substr($class, \strlen($prefix)));
    $file = __DIR__ . '/app/' . $relative . '.php';

    if (\is_file($file)) {
        require_once $file;
    }
});
