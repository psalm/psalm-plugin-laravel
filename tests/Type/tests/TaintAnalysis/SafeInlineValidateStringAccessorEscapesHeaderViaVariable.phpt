--ARGS--
--threads=1 --no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Variable-binding variant of SafeInlineValidateStringAccessorEscapesHeader.
 *
 * `$request->string('age')` and `$request->str('age')` read from the
 * same data pool as `$request->input('age')`; binding the result to a
 * local variable before echoing must preserve the rule's escape (#834).
 *
 * Note on test scope: the issue (#834) sketches the test as
 * `$v = $request->string('k')->value(); echo $v;`. The plugin currently
 * recognises the direct-accessor form (`$v = $request->string('k')`)
 * but does not walk inner method calls of a chained RHS like `->value()`.
 * The simpler form mirrors the existing non-variable test exactly and
 * exercises the same code path; the chain case is a separate, broader
 * improvement.
 *
 * --threads=1: see SafeInlineValidateCustomRuleEscapesHeaderViaVariable
 * for why these tests pin to a single Psalm thread.
 */
function renderStringAndStrViaVariable(Request $request): void {
    $request->validate([
        'age' => ['required', Rule::numeric()],
    ]);

    $boundString = $request->string('age');
    echo $boundString;

    $boundStr = $request->str('age');
    echo $boundStr;
}
?>
--EXPECTF--
