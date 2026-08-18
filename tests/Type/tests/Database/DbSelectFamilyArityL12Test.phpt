--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipFrom('13');
--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * Companion to DbCursorFetchUsingTest.phpt (#1375): on Laravel 12 the select family
 * (DB::select()/selectResultSets()/cursor(), Connection::select()/selectResultSets()/cursor())
 * has no $fetchUsing 4th param (`stubs/13/` does not load below Laravel 13). A 4-arg call must
 * report TooManyArguments, matching what the Laravel 12 runtime itself raises as ArgumentCountError.
 *
 * Also pins that the 3-arg cursor() call's return stays Generator<int, stdClass> on Laravel 12
 * too: the value type is not version-dependent (only the $fetchUsing param is), so it must not
 * regress to bare Generator when the common stub's parameter list is kept at 3-arg.
 */
function db_select_family_rejects_fetch_using_below_13(): void
{
    DB::cursor('select * from users', [], true, [\PDO::FETCH_ASSOC]);

    $_cursorRows = DB::cursor('select 1');
    /** @psalm-check-type-exact $_cursorRows = Generator<int, stdClass> */

    DB::select('select * from users', [], true, [\PDO::FETCH_ASSOC]);

    DB::selectResultSets('select 1; select 2', [], true, [\PDO::FETCH_ASSOC]);

    $connection = DB::connection();

    $connection->cursor('select * from users', [], true, [\PDO::FETCH_ASSOC]);

    $connection->select('select * from users', [], true, [\PDO::FETCH_ASSOC]);

    $connection->selectResultSets('select 1; select 2', [], true, [\PDO::FETCH_ASSOC]);
}
?>
--EXPECTF--
TooManyArguments on line %d: Too many arguments for Illuminate\Support\Facades\DB::cursor - expecting 3 but saw 4
TooManyArguments on line %d: Too many arguments for Illuminate\Support\Facades\DB::select - expecting 3 but saw 4
TooManyArguments on line %d: Too many arguments for Illuminate\Support\Facades\DB::selectResultSets - expecting 3 but saw 4
TooManyArguments on line %d: Too many arguments for method Illuminate\Database\Connection::cursor - saw 4
TooManyArguments on line %d: Too many arguments for method Illuminate\Database\Connection::select - saw 4
TooManyArguments on line %d: Too many arguments for method Illuminate\Database\Connection::selectresultsets - saw 4
