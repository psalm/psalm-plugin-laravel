--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Rules;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * `$request->string('key')` and `$request->str('key')` read from the
 * same data pool as `$request->input('key')`; the inline-validate rule's
 * escape must apply identically. With a `numeric` rule escaping all input
 * taint, echoing the Stringable produces no TaintedHtml. Mirrors the
 * FormRequest-path test `SafeFormRequestRuleNumericFluentNoTaint`.
 */
function renderStringAndStr(Request $request): void {
    $request->validate([
        'age' => ['required', Rule::numeric()],
    ]);

    echo $request->string('age');
    echo $request->str('age');
}
?>
--EXPECTF--
