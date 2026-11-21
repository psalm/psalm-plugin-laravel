--FILE--
<?php declare(strict_types=1);

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * KNOWN LIMITATION (see the `fragment()` docblock in stubs/common/Pagination/Pagination.phpstub): a possibly-empty
 * unpack may call the getter (`$args = []`) or the setter, but the result holds only the setter branch.
 * Psalm resolved both branches for a sealed `array{0?: string}` until 7.13.2; since 7.14 that shape unpacks
 * like a `list<string>` (vimeo/psalm#14415 changed how an array shape with integer keys is unpacked).
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
