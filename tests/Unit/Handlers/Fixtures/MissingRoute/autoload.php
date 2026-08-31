<?php

declare(strict_types=1);

// Standalone autoloader for the fixture — kept isolated from the package autoloader, same
// convention as tests/Unit/Handlers/Fixtures/UnknownModelAttribute/autoload.php.
\spl_autoload_register(static function (string $class): void {
    $prefix = 'MissingRouteFixture\\';

    if (!\str_starts_with($class, $prefix)) {
        return;
    }

    $relative = \str_replace('\\', '/', \substr($class, \strlen($prefix)));
    $file = __DIR__ . '/app/' . $relative . '.php';

    if (\is_file($file)) {
        require_once $file;
    }
});
