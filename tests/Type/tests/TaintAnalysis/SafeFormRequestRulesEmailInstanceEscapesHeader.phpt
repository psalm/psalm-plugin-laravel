--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Email;

/**
 * `new Rules\Email()` carries the same header/cookie escape as the 'email'
 * string rule. redirect()->to() still reports TaintedSSRF because the email
 * validator does not prevent SSRF (a valid email domain can resolve to an
 * internal host).
 */
final class ObjectEmailRequest extends FormRequest
{
    public function rules(): array
    {
        return ['team_email' => ['required', 'string', new Email()]];
    }
}

function direct(ObjectEmailRequest $request): \Illuminate\Http\RedirectResponse {
    return redirect()->to($request->safe()->input('team_email'));
}
?>
--EXPECTF--
%ATaintedSSRF on line %d: Detected tainted network request
