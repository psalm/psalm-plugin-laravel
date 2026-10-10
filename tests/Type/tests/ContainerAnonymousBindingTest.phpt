--FILE--
<?php declare(strict_types=1);

// The plugin rebinds 'Illuminate\Foundation\Bootstrap\HandleExceptions' to an anonymous class while
// booting its app, so make() returns an object whose class Psalm never scans (an anonymous class has
// no file Psalm can queue). The resolver must not name that `class@anonymous` object; it declines and
// falls back to the abstract, which is a loadable class.

function appHelperDeclinesAnonymousConcrete(): object
{
    $bootstrapper = app('Illuminate\Foundation\Bootstrap\HandleExceptions');
    /** @psalm-check-type-exact $bootstrapper = Illuminate\Foundation\Bootstrap\HandleExceptions */
    return $bootstrapper;
}
?>
--EXPECTF--
