<?php

declare(strict_types=1);

// Stands in for the project's Composer PSR-4 autoloader (composer.json maps `App\` to app/):
// compiling `<x-alert>` resolves the guessed `App\View\Components\Alert` with class_exists(), as
// it does in a real application.
\spl_autoload_register(static function (string $class): void {
    if (\str_starts_with($class, 'App\\')) {
        $file = \dirname(__DIR__) . '/app/' . \str_replace('\\', '/', \substr($class, 4)) . '.php';

        if (\is_file($file)) {
            require $file;
        }
    }
});
