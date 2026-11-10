--FILE--
<?php declare(strict_types=1);

/**
 * Same narrowing as `CarbonIsoWeekdayTest.phpt`, but the variable is typed on
 * `Carbon\CarbonInterface` so the stub on the interface (not the concrete
 * Carbon / CarbonImmutable classes) is what's load-bearing.
 *
 * The getter is `int<1, 7>` and the setter stays `static` on every supported Carbon
 * version (#1059).
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/922
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1059
 */

use Carbon\CarbonInterface;

function via_iface(CarbonInterface $c): int
{
    $dow = $c->isoWeekday();
    /** @psalm-check-type-exact $dow = int<1, 7> */
    return $dow;
}

function set_via_iface(CarbonInterface $c): CarbonInterface
{
    $monday = $c->isoWeekday(1);
    /** @psalm-check-type-exact $monday = \Carbon\CarbonInterface&static */
    return $monday;
}
?>
--EXPECTF--
