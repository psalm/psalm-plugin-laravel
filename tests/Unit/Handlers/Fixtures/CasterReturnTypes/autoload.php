<?php

declare(strict_types=1);

// Standalone autoloader for the fixture models: ModelRegistrationHandler only registers models it can
// load, so the fixture classes MUST be loadable in the Psalm subprocess.
\spl_autoload_register(static function (string $class): void {
    $prefix = 'CasterReturnFixture\\';

    if (!\str_starts_with($class, $prefix)) {
        return;
    }

    $relative = \str_replace('\\', '/', \substr($class, \strlen($prefix)));
    $file = __DIR__ . '/app/' . $relative . '.php';

    if (\is_file($file)) {
        require_once $file;
    }
});
