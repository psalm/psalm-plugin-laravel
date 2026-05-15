--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rule-based escape extends beyond validated() to safe()->input().
 * The 'email' rule escapes header/cookie taint kinds, so redirect()->to()
 * only fires TaintedSSRF — TaintedHeader is suppressed.
 */
final class EmailContactRequest extends FormRequest
{
    public function rules(): array
    {
        return ['team_email' => 'required|email'];
    }
}

function direct(EmailContactRequest $request): \Illuminate\Http\RedirectResponse {
    return redirect()->to($request->safe()->input('team_email'));
}
?>
--EXPECTF--
%ATaintedSSRF on line %d: Detected tainted network request
