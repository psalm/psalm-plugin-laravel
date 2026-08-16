--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * FormRequest counterpart of SafeInlineValidateClosureRuleEscapesCallable, using
 * an arrow function to pin the ArrowFunction node shape alongside Closure.
 *
 * The header-sinking redirect()->to() stays silent while the http-client ssrf
 * sink still fires, proving the value is still tainted and only the annotated
 * kind was removed.
 */
final class ContactArrowRequest extends FormRequest
{
    public function rules(): array
    {
        return ['team_email' => ['required', 'string',
            /** @psalm-taint-escape header */
            fn (string $attribute, mixed $value, \Closure $fail): mixed
                => $value === $attribute ? $fail('The email is invalid.') : null,
        ]];
    }
}

function direct(ContactArrowRequest $request): \Illuminate\Http\RedirectResponse {
    (new \Illuminate\Http\Client\PendingRequest())->get($request->safe()->input('team_email'));
    return redirect()->to($request->safe()->input('team_email'));
}
?>
--EXPECTF--
TaintedSSRF on line %d: Detected tainted network request
