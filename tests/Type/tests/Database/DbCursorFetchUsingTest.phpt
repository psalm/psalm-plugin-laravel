--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * Laravel 13 added a 4th $fetchUsing param to Connection::cursor() (laravel/framework#55394).
 * The concrete DB facade stub is authoritative over the generated pseudo-method (#1370), so it
 * must accept the same arity or a valid 4-arg call is rejected as TooManyArguments (#1372).
 */
function db_cursor_accepts_fetch_using(): void
{
    $_rows = DB::cursor('select * from users', [], true, [\PDO::FETCH_ASSOC]);
    /** @psalm-check-type-exact $_rows = Generator<int, stdClass> */

    $_defaultRows = DB::cursor('select 1');
    /** @psalm-check-type-exact $_defaultRows = Generator<int, stdClass> */

    $_connectionRows = DB::connection()->cursor('select * from users', [], true, [\PDO::FETCH_ASSOC]);
    /** @psalm-check-type-exact $_connectionRows = Generator<int, stdClass> */
}
?>
--EXPECTF--
