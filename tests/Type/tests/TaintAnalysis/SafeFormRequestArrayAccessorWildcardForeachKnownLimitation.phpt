--ARGS--
--threads=1 --no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;

/**
 * KNOWN LIMITATION — `foreach ($formRequest->array('k') as $e)` does NOT
 * receive the rule's escape on each element.
 *
 * `InlineValidateRulesCollector::beforeStatementAnalysis` only seeds the
 * loop variable's escape cache from inline-validate rules
 * (`$rulesByFunction`); `resolveEscapeFromAccessorRhs` does not consult
 * `ValidationRuleAnalyzer::getRulesForFormRequest()` for class-level
 * `rules()` definitions. Compounding this, Psalm's `arrayvalue-fetch`
 * for the iterable expression builds a flow edge from the source
 * declaration to the element that bypasses the `removeTaints` mask
 * applied to the call expression. So both layers miss the escape.
 *
 * The companion `SafeFormRequestArrayAccessorWildcardEscapesHeader`
 * locks in the FormRequest direct-pass shape, which works because
 * `removeTaints` fires on the call expression itself. The inline-validate
 * variant of this test
 * (`SafeInlineValidateArrayAccessorWildcardEscapesHeader`) covers the
 * supported foreach path.
 *
 * If a future change wires up FormRequest rules into the foreach loop
 * variable cache, this test flips to a `Safe*` expectation, forcing a
 * deliberate review.
 *
 * --threads=1: see SafeInlineValidateCustomRuleEscapesHeaderViaVariable
 * for why these tests pin to a single Psalm thread.
 */
final class WildcardEmailArrayForeachLimitationRequest extends FormRequest
{
    public function rules(): array
    {
        return ['emails.*' => ['required', Rule::email()]];
    }
}

/**
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedArgument
 */
function storeFormRequestArrayAccessorForeach(WildcardEmailArrayForeachLimitationRequest $request): RedirectResponse {
    foreach ($request->array('emails') as $email) {
        return redirect()->to($email);
    }

    return redirect()->to('/');
}
?>
--EXPECTF--
TaintedHeader on line %d: Detected tainted header
TaintedSSRF on line %d: Detected tainted network request
