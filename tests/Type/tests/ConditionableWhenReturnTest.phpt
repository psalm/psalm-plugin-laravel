--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;

/**
 * when()/unless() return `$callback(...) ?? $this` on one branch and `$default(...) ?? $this` on the
 * other, so both callbacks' returns join the receiver, whichever way they are passed (#1981).
 */
final class ConditionableWhenReturnTest
{
    /** @param Collection<int, int> $c */
    public function defaultReturnJoins(Collection $c, bool $flag): void
    {
        $_when = $c->when($flag, fn ($_x) => null, fn ($_x) => 'fallback');
        /** @psalm-check-type-exact $_when = Collection<int, int>&static|'fallback' */

        $_unless = $c->unless($flag, fn ($_x): int => 1, fn ($_x) => 'fallback');
        /** @psalm-check-type-exact $_unless = Collection<int, int>&static|'fallback'|1 */
    }

    /** @param Collection<int, int> $c */
    public function namedCallbackBinds(Collection $c, bool $flag): void
    {
        $_named = $c->when($flag, default: fn ($_x) => 7, callback: fn ($_x) => 'yes');
        /** @psalm-check-type-exact $_named = Collection<int, int>&static|'yes'|7 */
    }

    /** @param Collection<int, int> $c */
    public function sameClassOtherGenericsKept(Collection $c, bool $flag): void
    {
        $result = $c->when($flag, fn ($_x): Collection => collect(['key' => 'text']));
        $_first = $result->first();
        /** @psalm-check-type-exact $_first = 'text'|int|null */
    }

    /**
     * @param Collection<int, int> $c
     * @param list<callable> $rest
     */
    public function unpackedDefaultDeclines(Collection $c, bool $flag, array $rest): void
    {
        $_unpacked = $c->when($flag, fn (Collection $_x) => 'yes', ...$rest);
        /** @psalm-check-type-exact $_unpacked = Collection<int, int>&static */
    }
}
?>
--EXPECTF--
