--FILE--
<?php declare(strict_types=1);

/**
 * Same narrowing as `CarbonIsoWeekdayTest.phpt`, but the variable is typed on
 * `Carbon\CarbonInterface` so the stub on the interface (not the concrete
 * Carbon / CarbonImmutable classes) is what's load-bearing.
 *
 * The getter check is the non-exact `int` (not `int<1, 7>`) because the getter
 * type is version-dependent: the plugin narrows it to `int<1, 7>` only on
 * nesbot/carbon < 3.12; on >= 3.12 Carbon's own conditional yields the wider
 * `int` (the plugin skips its redeclaration there, see #1059). The setter check
 * stays exact: `static` is the form that regressed on carbon >= 3.12.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/922
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1059
 */

use Carbon\CarbonInterface;

function via_iface(CarbonInterface $c): int
{
    $dow = $c->isoWeekday();
    /** @psalm-check-type $dow = int */
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
