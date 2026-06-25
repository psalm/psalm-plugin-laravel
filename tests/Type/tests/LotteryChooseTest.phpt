--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Lottery;

function lottery_choose(Lottery $lottery): void
{
    // No count: a single run returns the callback result (mixed).
    $_one = $lottery->choose();
    /** @psalm-check-type-exact $_one = mixed */

    // A count returns the runs collected into a list.
    $_many = $lottery->choose(3);
    /** @psalm-check-type-exact $_many = list<mixed> */
}
?>
--EXPECTF--
