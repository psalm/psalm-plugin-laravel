<?php

declare(strict_types=1);

// The plugin reflects on models while Psalm boots, so the fixture models must be loadable in the
// Psalter subprocess. Case files under cases/ are only parsed, never loaded.
\spl_autoload_register(static function (string $class): void {
    $prefix = 'PsalterFixture\\Models\\';

    if (\str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/app/Models/' . \substr($class, \strlen($prefix)) . '.php';

        if (\is_file($file)) {
            require_once $file;
        }
    }
});
