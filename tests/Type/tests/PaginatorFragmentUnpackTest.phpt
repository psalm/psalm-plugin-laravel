--FILE--
<?php declare(strict_types=1);

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * A possibly-empty unpack may call the getter (`$args = []`) or the setter, so both branches apply.
 *
 * @param LengthAwarePaginator<int, string> $p
 * @param array{0?: string} $args
 */
function fragment_possibly_empty_unpack(LengthAwarePaginator $p, array $args): void
{
    $_result = $p->fragment(...$args);
    /** @psalm-check-type-exact $_result = LengthAwarePaginator<int, string>&static|null|string */
}
?>
--EXPECTF--
