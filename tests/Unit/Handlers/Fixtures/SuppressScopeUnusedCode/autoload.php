<?php

declare(strict_types=1);

// Standalone classmap-style autoloader for the fixture classes. The SuppressHandler reproduction
// is run as a separate Psalm subprocess (see SuppressScopeUnusedCodeTest) that loads the plugin,
// which reflects on the model at runtime — so the fixture classes must be loadable in that process.
// A dedicated, non-Composer namespace keeps the fixture isolated from the package autoloader.
\spl_autoload_register(static function (string $class): void {
    $prefix = 'ScopeUnusedCodeFixture\\';

    if (!\str_starts_with($class, $prefix)) {
        return;
    }

    $relative = \str_replace('\\', '/', \substr($class, \strlen($prefix)));
    $file = __DIR__ . '/app/' . $relative . '.php';

    if (\is_file($file)) {
        require_once $file;
    }
});
