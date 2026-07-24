--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Custom Rule class with a class-level @psalm-taint-escape. When used via
 * `new DnsEmailRule()` in a rules() array, the class-level escape is
 * OR-ed into the field's removedTaints, so the header-sinking
 * redirect()->to() stays silent; the http-client ssrf sink still fires
 * TaintedSSRF, proving the value itself is still tainted.
 *
 * The 'string' rule pins the type (so Redirector::to() doesn't see `mixed`)
 * but removes no taint, isolating the escape to the class-level annotation.
 *
 * @psalm-taint-escape header
 * @psalm-taint-escape cookie
 */
final class DnsEmailRule implements ValidationRule
{
    #[\Override]
    public function validate(string $attribute, mixed $value, \Closure $fail): void {}
}

final class ContactRequestNew extends FormRequest
{
    public function rules(): array
    {
        return ['team_email' => ['required', 'string', new DnsEmailRule()]];
    }
}

function direct(ContactRequestNew $request): \Illuminate\Http\RedirectResponse {
    (new \Illuminate\Http\Client\PendingRequest())->get($request->safe()->input('team_email'));
    return redirect()->to($request->safe()->input('team_email'));
}
?>
--EXPECTF--
TaintedSSRF on line %d: Detected tainted network request
