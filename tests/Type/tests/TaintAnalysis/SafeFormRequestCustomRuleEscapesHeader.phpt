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
 * OR-ed into the field's removedTaints, so redirect()->to() only fires
 * TaintedSSRF — TaintedHeader is suppressed.
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
    return redirect()->to($request->safe()->input('team_email'));
}
?>
--EXPECTF--
TaintedSSRF on line %d: Detected tainted network request
