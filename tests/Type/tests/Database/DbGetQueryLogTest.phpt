--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\DB;

// The facade's generated `@method static array[] getQueryLog()` drops the entry shape
// that Connection::getQueryLog() documents.

function first_logged_query(): ?string
{
    /** @psalm-check-type-exact $log = list<array{bindings: array<array-key, mixed>, query: string, readWriteType?: null|string, time: float|null}> */
    $log = DB::getQueryLog();

    return $log[0]['query'] ?? null;
}
?>
--EXPECTF--
