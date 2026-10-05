<?php

declare(strict_types=1);

// Psalm's scanner only parses the AST, never `require`s the file — so the class stays unloaded
// until an autoloading call (the pre-fix bug) fires it here. `AutoloadCrashFixture\X` lives in `app/X.php`.
//
// `include`, not `include_once`, like Composer's ClassLoader: a file whose load threw declares no
// class, so the next autoloading call includes it (and fires its deprecation) again.
\spl_autoload_register(static function (string $class): void {
    $prefix = 'AutoloadCrashFixture\\';

    if (!\str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/app/' . \str_replace('\\', '/', \substr($class, \strlen($prefix))) . '.php';

    if (\is_file($file)) {
        include $file;
    }
});
