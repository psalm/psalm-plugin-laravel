<?php

declare(strict_types=1);

// Standalone autoloader for the fixture app class. A dedicated, non-Composer namespace keeps the
// fixture isolated from the package autoloader, same idiom as Fixtures/UnknownModelAttribute.
\spl_autoload_register(static function (string $class): void {
    $prefix = 'UnknownDiskFixture\\';

    if (!\str_starts_with($class, $prefix)) {
        return;
    }

    $relative = \str_replace('\\', '/', \substr($class, \strlen($prefix)));
    $file = __DIR__ . '/app/' . $relative . '.php';

    if (\is_file($file)) {
        require_once $file;
    }
});
