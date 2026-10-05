<?php

declare(strict_types=1);

// Psalm's scanner only parses the AST, never `require`s the file — so the class stays unloaded
// until an autoloading call (the pre-fix bug) fires it here.
\spl_autoload_register(static function (string $class): void {
    $prefix = 'AutoloadCrashFixture\\';

    if (!\str_starts_with($class, $prefix)) {
        return;
    }

    $relative = \str_replace('\\', '/', \substr($class, \strlen($prefix)));
    $file = __DIR__ . '/app/' . $relative . '.php';

    if (\is_file($file)) {
        require_once $file;
    }
});
