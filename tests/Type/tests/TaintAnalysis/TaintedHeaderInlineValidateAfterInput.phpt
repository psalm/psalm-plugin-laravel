--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Rules;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * `validate()` collector fires at the end of a method call. A `$request->input()`
 * that appears BEFORE the `$request->validate()` in source order must not
 * get the rule's escape — the cache is empty at the point the read is analyzed.
 *
 * @psalm-taint-escape header
 * @psalm-taint-escape cookie
 */
final class LateDnsRule implements ValidationRule
{
    #[\Override]
    public function validate(string $attribute, mixed $value, \Closure $fail): void {}
}

/** @psalm-suppress MixedArgument */
function store(Request $request): RedirectResponse {
    $redirect = redirect()->to($request->input('contact_email'));

    $request->validate([
        'contact_email' => ['required', 'string', new LateDnsRule()],
    ]);

    return $redirect;
}
?>
--EXPECTF--
TaintedHeader on line %d: Detected tainted header
TaintedSSRF on line %d: Detected tainted network request
