--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Rules;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The cache of inline validate() rules is scoped to the function that
 * contains the `$request->validate([...])` call. A validate() in one
 * function MUST NOT silence TaintedHeader on a read in a different
 * function — the analyzer cannot assume the validator helper is always
 * invoked before the reader.
 *
 * @psalm-taint-escape header
 * @psalm-taint-escape cookie
 */
final class ScopedDnsRule implements ValidationRule
{
    #[\Override]
    public function validate(string $attribute, mixed $value, \Closure $fail): void {}
}

function validator(Request $request): void {
    $request->validate([
        'contact_email' => ['required', 'string', new ScopedDnsRule()],
    ]);
}

/** @psalm-suppress MixedArgument */
function read(Request $request): RedirectResponse {
    return redirect()->to($request->input('contact_email'));
}
?>
--EXPECTF--
TaintedHeader on line %d: Detected tainted header
TaintedSSRF on line %d: Detected tainted network request
