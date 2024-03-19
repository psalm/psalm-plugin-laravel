--FILE--
<?php declare(strict_types=1);

function test(\Illuminate\Database\Migrations\Migrator $migrator): int {
    return $migrator->usingConnection('default', function () {
        return 1;
    });
}
?>
--EXPECTF--
