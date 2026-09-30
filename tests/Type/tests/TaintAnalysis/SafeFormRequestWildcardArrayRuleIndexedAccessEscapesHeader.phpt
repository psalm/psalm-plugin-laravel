--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Wildcard-suffix rule on a FormRequest: `'emails.*' => [..., Rule::email()]`
 * stored under the parent key `'emails'` by `resolveRules()`. Accessing an
 * indexed element via `$request->input('emails.0')` must strip the trailing
 * numeric segment and apply the element rule's header/cookie escape.
 *
 * The fix sits in `ValidationRuleAnalyzer::lookupRuleByKey`, called by
 * `ValidationTaintHandler::removeTaints` from its FormRequest-rules branch.
 * This test exercises that branch specifically, complementing the inline
 * `$request->validate([...])` tests in `SafeInlineValidateWildcardArrayRule*`.
 *
 * TaintedSSRF still fires: a valid email's domain may still resolve to an
 * internal host. Psalm 6 reports it TWICE for this single sink call (same
 * type, line, and message, #1359); Psalm 7.0.0-beta21+ reports it once.
 */
final class WildcardEmailRequest extends FormRequest
{
    public function rules(): array
    {
        return ['emails.*' => ['required', Rule::email()]];
    }
}

/** @psalm-suppress MixedArgument */
function storeWildcardFormRequest(WildcardEmailRequest $request): \Illuminate\Http\RedirectResponse {
    (new \Illuminate\Http\Client\PendingRequest())->get($request->input('emails.0'));
    return redirect()->to($request->input('emails.0'));
}
?>
--EXPECTF--
TaintedSSRF on line %d: Detected tainted network request
TaintedSSRF on line %d: Detected tainted network request
