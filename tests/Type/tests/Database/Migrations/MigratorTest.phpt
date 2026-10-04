--FILE--
<?php declare(strict_types=1);

function test(\Illuminate\Database\Migrations\Migrator $migrator): int {
    return $migrator->usingConnection('default', function () {
        return 1;
    });
}

/** The migrate commands pass the nullable --database option straight through. */
function usingDefaultConnection(\Illuminate\Database\Migrations\Migrator $migrator, ?string $database): int {
    return $migrator->usingConnection($database, static fn (): int => 1);
}
?>
--EXPECTF--
