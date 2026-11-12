--FILE--
<?php declare(strict_types=1);

/**
 * PHP makes a class or interface `Stringable` when it declares `__toString()`.
 * A stub that redeclares it without `__toString()` must list `Stringable`,
 * or Psalm stops treating it (and every implementor) as `Stringable`.
 */

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon as IlluminateCarbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\LazyCollection;

/** @return array<string, scalar|\Stringable|null> */
function report_row(?Carbon $lastReferralDate): array
{
    return ['last_referral_date' => $lastReferralDate];
}

function from_interface(CarbonInterface $date): \Stringable
{
    return $date;
}

function from_immutable(CarbonImmutable $date): \Stringable
{
    return $date;
}

function from_illuminate_carbon(IlluminateCarbon $date): \Stringable
{
    return $date;
}

function from_period(CarbonPeriod $period): \Stringable
{
    return $period;
}

/** @param Enumerable<int, string> $items */
function from_enumerable(Enumerable $items): \Stringable
{
    return $items;
}

/** @param Collection<int, string> $items */
function from_collection(Collection $items): \Stringable
{
    return $items;
}

/** @param LazyCollection<int, string> $items */
function from_lazy_collection(LazyCollection $items): \Stringable
{
    return $items;
}
?>
--EXPECTF--
