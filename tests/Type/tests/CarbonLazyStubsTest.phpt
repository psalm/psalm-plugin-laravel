--FILE--
<?php declare(strict_types=1);

use Carbon\CarbonPeriod;
use Carbon\Translator;
use Carbon\MessageFormatter\MessageFormatterMapper;

// Carbon loads three classes (DatePeriodBase, LazyTranslator, LazyMessageFormatter)
// from vendor/nesbot/carbon/lazy/ via runtime `require`. That directory is not in
// Carbon's composer autoload, so without the plugin's stub registration Psalm
// reports MissingDependency on the classes below.

// CarbonPeriod extends Carbon\DatePeriodBase — iteration relies on the parent.
function iterate_carbon_period(CarbonPeriod $period): array
{
    $dates = [];
    foreach ($period as $date) {
        $dates[] = $date->format('Y-m-d');
    }
    return $dates;
}

$_period = CarbonPeriod::create('2026-01-01', '1 day', '2026-01-10');
/** @psalm-check-type-exact $_period = \Carbon\CarbonPeriod */

// Exercises a CarbonPeriod method that returns CarbonInterface. If DatePeriodBase
// stays unresolved Psalm widens the return to mixed, and this declared type fails.
function get_start(CarbonPeriod $period): \Carbon\CarbonInterface
{
    return $period->getStartDate();
}

// Translator extends Carbon\LazyTranslator.
function translate(Translator $translator): string
{
    return $translator->trans('greeting');
}

// MessageFormatterMapper extends Carbon\MessageFormatter\LazyMessageFormatter.
function format_message(MessageFormatterMapper $mapper): string
{
    return $mapper->format('hello', 'en', []);
}
?>
--EXPECTF--
