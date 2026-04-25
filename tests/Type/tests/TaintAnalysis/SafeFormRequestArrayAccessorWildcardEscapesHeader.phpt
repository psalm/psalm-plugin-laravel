--ARGS--
--threads=1 --no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Validation\Rule;

/**
 * FormRequest variant of issue #840: a typed FormRequest with a
 * wildcard rule on `'emails.*'`, then `$req->array('emails')` reads the
 * whole validated array. The rule's email escape applies at the call
 * expression's outgoing taint, so passing the array directly to a
 * header sink (`Mail::cc()` accepts iterable) does not fire
 * TaintedHeader.
 *
 * Foreach iteration over a FormRequest's `array()` is not exercised
 * here. Element extraction via `arrayvalue-fetch` requires the
 * loop-variable cache populated in
 * `InlineValidateRulesCollector::beforeStatementAnalysis`, which today
 * only seeds from inline-validate rules — not from FormRequest's
 * `rules()` method. Direct pass to a sink that accepts the whole
 * array/Collection is the well-supported FormRequest shape and is what
 * this test locks in.
 *
 * --threads=1: see SafeInlineValidateCustomRuleEscapesHeaderViaVariable
 * for why these tests pin to a single Psalm thread.
 */
final class WildcardEmailArrayRequest extends FormRequest
{
    public function rules(): array
    {
        return ['emails.*' => ['required', Rule::email()]];
    }
}

function storeFormRequestArrayAccessor(WildcardEmailArrayRequest $request, Mailable $mail): void {
    $mail->cc($request->array('emails'));
}
?>
--EXPECTF--
