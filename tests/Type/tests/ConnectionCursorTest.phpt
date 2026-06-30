--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\Connection;

/**
 * Connection::cursor() yields stdClass rows. The stub narrows the bare \Generator
 * return to \Generator<int, \stdClass> so callers iterate typed rows instead of mixed.
 */
final class ConnectionCursorTest
{
    public function cursorYieldsStdClassRows(Connection $connection): void
    {
        $_rows = $connection->cursor('select * from users');
        /** @psalm-check-type-exact $_rows = Generator<int, stdClass> */
    }
}
?>
--EXPECTF--
