--FILE--
<?php declare(strict_types=1);

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Part;
use App\Models\WorkOrder;

/**
 * A dynamic where{Column}() argument is `mixed`, as with where(): Laravel forwards it to
 * Query\Builder::where(), which takes null (whereNull), arrays, Expressions, BackedEnums,
 * DateTimeInterface and Closure/Builder subqueries, so no @property column type is imposed.
 * An empty --EXPECTF-- therefore also proves the dynamic where is recognised (an unknown
 * column would raise UndefinedMagicMethod on the model forms).
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/2067
 */

function test_model_static_accepts_any_argument(mixed $anything): void {
    Part::whereName(null);
    Part::whereName(123);
    Part::whereName(['a', 'b']);
    Part::whereName(new DateTime());
    Part::whereName($anything);
    Customer::whereVehiclesCount('12');
}

function test_model_instance_accepts_any_argument(): void {
    (new Part())->whereName(null);
    (new Part())->whereUnitPrice('9.99');
}

function test_relation_chain_accepts_any_argument(mixed $anything): void {
    $invoice = (new WorkOrder())->invoice();
    $invoice->whereStatus(null);
    $invoice->whereStatus(123);
    $invoice->whereStatus(['paid', 'sent']);
    $invoice->whereStatus(new DateTime());
    $invoice->whereStatus($anything);
    (new Admin())->customers()->whereVehiclesCount('12');
}

// Laravel ignores the arguments past the first; the operator form is tolerated.
function test_operator_form_is_tolerated(): void {
    (new WorkOrder())->invoice()->whereStatus('=', 'paid');
    Part::whereName('=', 'x');
}
?>
--EXPECTF--
