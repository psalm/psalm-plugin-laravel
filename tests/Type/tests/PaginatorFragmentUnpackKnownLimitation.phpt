--FILE--
<?php declare(strict_types=1);

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * KNOWN LIMITATION: a possibly-empty unpack binds `TFragment` from the element type and misses
 * the getter branch, so `$p->fragment(...$args)` infers only the paginator although `$args = []`
 * calls the getter (`string|null`). Laravel 13.18+'s native conditional behaves identically
 * (Psalm's unpacked-argument template inference). See the caveat on `AbstractPaginator::fragment()`
 * in stubs/common/Pagination/Pagination.phpstub. A Psalm fix should flip this to a union that
 * includes `string|null`.
 *
 * @param LengthAwarePaginator<int, string> $p
 * @param array{0?: string} $args
 */
function fragment_possibly_empty_unpack(LengthAwarePaginator $p, array $args): void
{
    $_result = $p->fragment(...$args);
    /** @psalm-check-type-exact $_result = LengthAwarePaginator<int, string>&static */
}
?>
--EXPECTF--
