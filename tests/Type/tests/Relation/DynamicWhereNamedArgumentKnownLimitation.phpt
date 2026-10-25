--FILE--
<?php declare(strict_types=1);

use App\Models\Part;
use App\Models\WorkOrder;

/**
 * Known limitation: a named argument on a dynamic where{Column}() is not checked. Laravel reads
 * the values by position, so these calls fail at runtime, but the variadic mixed signature
 * accepts any name (a single `$value` parameter breaks builder macros that run with their own
 * parameter names). See the dynamic where entry in docs/contributing/decisions.md.
 */
function test_named_argument_is_not_reported(): void {
    Part::whereName(name: 'x');
    (new WorkOrder())->invoice()->whereStatus(status: 'paid');
}
?>
--EXPECTF--
