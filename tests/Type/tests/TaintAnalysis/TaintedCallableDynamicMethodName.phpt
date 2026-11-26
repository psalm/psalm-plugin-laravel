--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;

/**
 * A request value that names the method of a call lets the client pick which method runs,
 * as `[$customer, $name]()` already reports: instance calls, static calls on a model and
 * the `__call()` forwarding of Eloquent models included.
 */
function runAction(Request $request, Customer $customer): void {
    $action = $request->string('action')->toString();

    $customer->$action();
}

function runScope(Request $request): void {
    $scope = $request->string('scope')->toString();

    Customer::$scope();
}

function runRouteAction(Request $request, Customer $customer): void {
    $action = $request->route('action');

    if (\is_string($action)) {
        $customer->$action();
    }
}
?>
--EXPECTF--
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
