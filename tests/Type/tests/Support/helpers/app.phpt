--FILE--
<?php declare(strict_types=1);

function returns_bool(string $env) {
    return app()->environment($env);
}

function returns_string(): string {
    return app()->environment();
}
?>
--EXPECTF--
