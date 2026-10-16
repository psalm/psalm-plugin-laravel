--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipFrom('13.0.0');
--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;

/**
 * Every 12.x sum() calls `$callback($item)` with one argument, so a callback that requires the
 * key would fatal with ArgumentCountError.
 *
 * @param Collection<string, int> $items
 */
function sum_callback_receives_item_only_on_12(Collection $items): void
{
    $_sum = $items->sum(fn (int $item): int => $item);
    /** @psalm-check-type-exact $_sum = int */

    $items->sum(fn (int $item, string $key): int => $item + \strlen($key));
}

/**
 * @param Enumerable<string, int> $items
 */
function enumerable_sum_untyped_callback_on_12(Enumerable $items): void
{
    $items->sum(function ($item) {
        /** @psalm-check-type-exact $item = int */
        return $item;
    });
}
?>
--EXPECTF--
InvalidScalarArgument on line %d: Argument 1 of Illuminate\Support\Collection::sum expects callable[impure](int):(float|int|numeric-string)|null|string, but Closure[pure](int, string):int<min, max> provided
