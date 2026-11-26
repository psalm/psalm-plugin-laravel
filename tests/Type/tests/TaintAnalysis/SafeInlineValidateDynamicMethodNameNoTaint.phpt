--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;

/**
 * An `in:` rule bounds the method name to the listed actions, so calling the method it
 * names is safe.
 */
function runAction(Request $request, Customer $customer): void {
    $request->validate([
        'action' => 'required|string|in:touch,refresh',
    ]);

    $customer->{$request->string('action')->toString()}();
}
?>
--EXPECTF--
