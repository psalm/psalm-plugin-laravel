--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Fluent `Rule::email()` resolves to `Rules\Email` via the Rule facade
 * method map. The resulting class carries the same header/cookie escape
 * as `'email'` and `new Rules\Email()`. redirect()->to() still reports
 * TaintedSSRF because email validation does not constrain resolution.
 */
final class FluentEmailRequest extends FormRequest
{
    public function rules(): array
    {
        return ['team_email' => ['required', 'string', Rule::email()]];
    }
}

function direct(FluentEmailRequest $request): \Illuminate\Http\RedirectResponse {
    return redirect()->to($request->safe()->input('team_email'));
}
?>
--EXPECTF--
%ATaintedSSRF on line %d: Detected tainted network request
