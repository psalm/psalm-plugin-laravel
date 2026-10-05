<?php

declare(strict_types=1);

// Psalm's scanner only parses the AST, never `require`s the file — so the class stays unloaded
// until an autoloading call (the pre-fix bug) fires it here.
//
// `AutoloadCrashFixture\Cases\<Case>\X` lives in `cases/<Case>/X.php` (one Psalm project per case);
// everything else in `app/`.
//
// `include`, not `include_once`, like Composer's ClassLoader: a file whose load threw declares no
// class, so the next autoloading call includes it (and fires its deprecation) again.
\spl_autoload_register(static function (string $class): void {
    $prefix = 'AutoloadCrashFixture\\';

    if (!\str_starts_with($class, $prefix)) {
        return;
    }

    $relative = \str_replace('\\', '/', \substr($class, \strlen($prefix)));
    $file = \str_starts_with($relative, 'Cases/')
        ? __DIR__ . '/cases/' . \substr($relative, \strlen('Cases/')) . '.php'
        : __DIR__ . '/app/' . $relative . '.php';

    if (\is_file($file)) {
        include $file;
    }
});
