<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

// Stands in for the project's Composer PSR-4 autoloader: component discovery reflects the classes
// it finds, so they must be autoloadable the way they are in a real application.
\spl_autoload_register(static function (string $class): void {
    $prefix = 'ComponentViewsFixture\\';

    if (\str_starts_with($class, $prefix)) {
        $file = \dirname(__DIR__) . '/app/' . \str_replace('\\', '/', \substr($class, \strlen($prefix))) . '.php';

        if (\is_file($file)) {
            require $file;
        }
    }
});

return Application::configure(basePath: \dirname(__DIR__))->create();
