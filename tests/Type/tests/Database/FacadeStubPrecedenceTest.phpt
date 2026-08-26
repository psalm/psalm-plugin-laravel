--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\DB;

function exact_string(string $value): string
{
    return $value;
}

function database_facade_stub_returns_outrank_generated_pseudo_returns(): void
{
    $_transaction = DB::transaction(static fn (): string => exact_string('done'));
    /** @psalm-check-type-exact $_transaction = string */

    $_one = DB::selectOne('select 1');
    /** @psalm-check-type-exact $_one = null|stdClass */

    $_scalar = DB::scalar('select 1');
    /** @psalm-check-type-exact $_scalar = null|scalar */

    $_rows = DB::select('select 1');
    /** @psalm-check-type-exact $_rows = list<stdClass> */

    $_writeRows = DB::selectFromWriteConnection('select 1');
    /** @psalm-check-type-exact $_writeRows = list<stdClass> */

    $_resultSets = DB::selectResultSets('select 1');
    /** @psalm-check-type-exact $_resultSets = list<list<stdClass>> */
}
?>
--EXPECTF--
