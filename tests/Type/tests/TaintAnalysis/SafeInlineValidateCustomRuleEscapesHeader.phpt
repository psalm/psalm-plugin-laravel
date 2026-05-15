--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Rules;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Inline `$request->validate([...])` with a custom Rule class carrying a
 * class-level @psalm-taint-escape. The subsequent $request->input('field')
 * must honour the same escape as the equivalent FormRequest::rules() form,
 * so redirect()->to() only reports TaintedSSRF — TaintedHeader is removed.
 *
 * @psalm-taint-escape header
 * @psalm-taint-escape cookie
 */
final class InlineDnsRule implements ValidationRule
{
    #[\Override]
    public function validate(string $attribute, mixed $value, \Closure $fail): void {}
}

/** @psalm-suppress MixedArgument */
function store(Request $request): RedirectResponse {
    $request->validate([
        'contact_email' => ['required', 'string', new InlineDnsRule()],
    ]);

    return redirect()->to($request->input('contact_email'));
}
?>
--EXPECTF--
TaintedSSRF on line %d: Detected tainted network request
