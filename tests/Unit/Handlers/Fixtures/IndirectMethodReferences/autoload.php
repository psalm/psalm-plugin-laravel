<?php

declare(strict_types=1);

\spl_autoload_register(static function (string $class): void {
    $prefix = 'IndirectMethodReferencesFixture\\';
    if (!\str_starts_with($class, $prefix)) {
        return;
    }

    $relative = \str_replace('\\', '/', \substr($class, \strlen($prefix)));
    $file = __DIR__ . '/app/' . $relative . '.php';
    if (\is_file($file)) {
        require_once $file;
        return;
    }

    // Dependencies intentionally share one file to keep the fixture compact.
    if (\str_starts_with($class, 'IndirectMethodReferencesFixture\\Dependencies\\')) {
        require_once __DIR__ . '/app/Dependencies/Dependencies.php';
    }
});
