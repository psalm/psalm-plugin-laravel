--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.0.0');
--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;

/**
 * Laravel 13.2.0 made sum() pass the key as a second callback argument
 * (`$callback($item, $key)`); stubs/13/ declares it, 12.x calls with the item only.
 *
 * @param Collection<string, int> $items
 */
function sum_callback_receives_key_on_13(Collection $items): void
{
    $_sum = $items->sum(fn (int $item, string $key): int => $item + \strlen($key));
    /** @psalm-check-type-exact $_sum = int */

    $items->sum(function ($item, $key) {
        /** @psalm-check-type-exact $item = int */
        /** @psalm-check-type-exact $key = string */
        return $item + \strlen($key);
    });
}

/**
 * @param Enumerable<string, int> $items
 */
function enumerable_sum_callback_receives_key_on_13(Enumerable $items): void
{
    $items->sum(function ($item, $key) {
        /** @psalm-check-type-exact $item = int */
        /** @psalm-check-type-exact $key = string */
        return $item + \strlen($key);
    });
}
?>
--EXPECTF--
