--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\Connection;

function connection_query_log(Connection $connection): void
{
    $_log = $connection->getQueryLog();
    /** @psalm-check-type-exact $_log = list<array{bindings: array<array-key, mixed>, query: string, readWriteType?: null|string, time: float|null}> */
}
?>
--EXPECTF--
