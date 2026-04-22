--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The FormRequest path and the inline-validate path OR their escape bits.
 * Here rules() supplies the 'email' string rule (header + cookie escape)
 * and the inline validate() adds a class-level rule that also escapes
 * 'sql'. A subsequent $request->input('email') passes through both paths;
 * redirect()->to() reports only TaintedSSRF, confirming the header/cookie
 * escape from rules() survived the OR-merge with the inline path.
 *
 * @psalm-taint-escape sql
 */
final class InlineSqlEscapeRule implements ValidationRule
{
    #[\Override]
    public function validate(string $attribute, mixed $value, \Closure $fail): void {}
}

final class MergeRequest extends FormRequest
{
    public function rules(): array
    {
        return ['email' => ['required', 'email']];
    }
}

/** @psalm-suppress MixedArgument */
function storeMerge(MergeRequest $request): \Illuminate\Http\RedirectResponse {
    // rules() already supplied the 'email' rule; the inline validate adds
    // an extra constraint via a custom Rule class. Both must pass.
    $request->validate([
        'email' => ['required', new InlineSqlEscapeRule()],
    ]);

    return redirect()->to($request->input('email'));
}
?>
--EXPECTF--
TaintedSSRF on line %d: Detected tainted network request
