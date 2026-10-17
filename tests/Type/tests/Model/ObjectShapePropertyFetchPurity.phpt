--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox\ObjectShapeFetchPurity;

use App\Models\Customer;

// Reading a magic property is a `__get` call: the handler removes the false UndefinedMagicPropertyFetch of a
// shaped fetch but not the impurity Psalm reports for that call (vimeo/psalm#12110 workaround, #1944).

/**
 * @psalm-pure
 * @param Customer&object{extra: string} $m
 */
function pure_shaped_fetch(Customer $m): string
{
    return $m->extra;
}

/**
 * @psalm-pure
 */
function pure_plain_fetch(Customer $m): mixed
{
    return $m->extra;
}
?>
--EXPECTF--
ImpureMethodCall on line %d: The context is pure but method App\Models\Customer::__get requires write-props|read-globals|write-globals|io
UndefinedMagicPropertyFetch on line %d: Magic instance property App\Models\Customer::$extra is not defined
ImpureMethodCall on line %d: The context is pure but method App\Models\Customer::__get requires write-props|read-globals|write-globals|io
