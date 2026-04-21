--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Same as SafeFormRequestCustomRuleEscapesHeader, but built via a static
 * factory `FactoryDnsEmailRule::make()` instead of `new`. The class-level
 * @psalm-taint-escape still applies — the synthetic `class:...` segment is
 * captured from StaticCall nodes too.
 *
 * @psalm-taint-escape header
 * @psalm-taint-escape cookie
 */
final class FactoryDnsEmailRule implements ValidationRule
{
    public static function make(): self
    {
        return new self();
    }

    #[\Override]
    public function validate(string $attribute, mixed $value, \Closure $fail): void {}
}

final class ContactRequestFactory extends FormRequest
{
    public function rules(): array
    {
        return ['team_email' => ['required', 'string', FactoryDnsEmailRule::make()]];
    }
}

function direct(ContactRequestFactory $request): \Illuminate\Http\RedirectResponse {
    return redirect()->to($request->safe()->input('team_email'));
}
?>
--EXPECTF--
TaintedSSRF on line %d: Detected tainted network request
