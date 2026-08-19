--FILE--
<?php declare(strict_types=1);

namespace App\AppendsAndMutatedAttributesListGate;

use App\Models\Customer;

/**
 * `getAppends()` returns `$this->appends`, declared `protected $appends = []`; `append()` and
 * `mergeAppends()` wrap it in `array_values(array_unique(...))`, and `getArrayableAppends()`
 * requires a `list<string>` for `array_combine()` to work (#1378). `getMutatedAttributes()` returns
 * `static::$mutatorCache[static::class]`, populated by `cacheMutatedAttributes()` from
 * `preg_match_all(...)[1]` merged with `getAttributeMarkedMutatorMethods()` (`->values()->all()`),
 * both lists, `->map(lcfirst(...))->all()` preserving list-ness. Laravel's own `@return array`
 * leaves both bare, inferring `array<array-key, mixed>` without this stub.
 */
function get_appends_returns_list_of_strings(Customer $customer): array
{
    $appends = $customer->getAppends();
    /** @psalm-check-type-exact $appends = list<string> */

    $first = $appends[0];
    /** @psalm-check-type-exact $first = string */

    return [$first, ...$appends];
}

function get_mutated_attributes_returns_list_of_strings(Customer $customer): array
{
    $mutated = $customer->getMutatedAttributes();
    /** @psalm-check-type-exact $mutated = list<string> */

    $first = $mutated[0];
    /** @psalm-check-type-exact $first = string */

    return [$first, ...$mutated];
}
?>
--EXPECTF--
