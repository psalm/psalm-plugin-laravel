--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13');
--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * Laravel 13.0 added a 4th $fetchUsing param to the select family (laravel/framework#55394):
 * DB::select()/selectResultSets()/cursor() and Connection::select()/selectResultSets()/cursor().
 * The concrete DB facade stub is authoritative over the generated pseudo-method (#1370), so it
 * must accept the same arity or a valid 4-arg call is rejected as TooManyArguments (#1372).
 * `stubs/13/` widens the arity for Laravel 13+ only (#1375); this test covers that side, the
 * companion DbSelectFamilyArityL12Test.phpt covers the pre-13 side.
 *
 * stdClass is only true under PDO's DEFAULT fetch mode. A non-default $fetchUsing (e.g.
 * PDO::FETCH_ASSOC) makes PDOStatement::fetch()/fetchAll() yield arrays, not stdClass — the
 * stub must not claim stdClass unconditionally (#1372 acceptance criteria). Each 4-arg call
 * below both proves the arity is accepted AND that the value type honestly degrades to mixed.
 */
function db_select_family_accepts_fetch_using(): void
{
    $_defaultCursorRows = DB::cursor('select 1');
    /** @psalm-check-type-exact $_defaultCursorRows = Generator<int, stdClass> */

    $_cursorRows = DB::cursor('select * from users', [], true, [\PDO::FETCH_ASSOC]);
    /** @psalm-check-type-exact $_cursorRows = Generator<int, mixed> */

    $_defaultSelectRows = DB::select('select 1');
    /** @psalm-check-type-exact $_defaultSelectRows = list<stdClass> */

    $_selectRows = DB::select('select * from users', [], true, [\PDO::FETCH_ASSOC]);
    /** @psalm-check-type-exact $_selectRows = list<mixed> */

    $_defaultResultSets = DB::selectResultSets('select 1');
    /** @psalm-check-type-exact $_defaultResultSets = list<list<stdClass>> */

    $_resultSets = DB::selectResultSets('select 1; select 2', [], true, [\PDO::FETCH_ASSOC]);
    /** @psalm-check-type-exact $_resultSets = list<list<mixed>> */

    $connection = DB::connection();

    $_defaultConnectionCursorRows = $connection->cursor('select 1');
    /** @psalm-check-type-exact $_defaultConnectionCursorRows = Generator<int, stdClass> */

    $_connectionCursorRows = $connection->cursor('select * from users', [], true, [\PDO::FETCH_ASSOC]);
    /** @psalm-check-type-exact $_connectionCursorRows = Generator<int, mixed> */

    $_connectionSelectRows = $connection->select('select * from users', [], true, [\PDO::FETCH_ASSOC]);
    /** @psalm-check-type-exact $_connectionSelectRows = array */
}
?>
--EXPECTF--
