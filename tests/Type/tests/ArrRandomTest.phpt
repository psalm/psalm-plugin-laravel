--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Arr;

/**
 * @param array<string, int> $array
 */
function arr_random(array $array): void
{
    // No count: a single random element (mixed).
    $_one = Arr::random($array);
    /** @psalm-check-type-exact $_one = mixed */

    // Explicit null is the same getter form.
    $_explicit_null = Arr::random($array, null);
    /** @psalm-check-type-exact $_explicit_null = mixed */

    // A count returns that many elements as an array.
    $_many = Arr::random($array, 2);
    /** @psalm-check-type-exact $_many = array<array-key, mixed> */
}

/**
 * Re-declaring Arr to narrow random() must not drop its other static helpers —
 * they merge from reflection.
 *
 * @param array<string, int> $array
 */
function arr_other_helpers_still_resolve(array $array): void
{
    $_get = Arr::get($array, 'key');
    $_first = Arr::first($array);
    $_exists = Arr::exists($array, 'key');
    /** @psalm-check-type-exact $_exists = bool */
}
?>
--EXPECTF--
