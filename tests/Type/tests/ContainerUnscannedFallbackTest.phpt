--FILE--
<?php declare(strict_types=1);

// `Monolog\Handler\StreamHandler` is unbound and its constructor needs a stream, so make() throws
// after reflecting (and thereby loading) the class. Named only by a string here, nothing makes Psalm
// scan it, so the abstract-itself fallback must decline: naming a class with no storage reports
// UndefinedClass at the use site even though PHP has it loaded.

function appHelperDeclinesLoadedButUnscannedClass(): mixed
{
    /** @psalm-suppress MixedAssignment */
    $handler = app('Monolog\Handler\StreamHandler');
    /** @psalm-check-type-exact $handler = mixed */
    return $handler;
}
?>
--EXPECTF--
