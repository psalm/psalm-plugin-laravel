--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The chained form `Rule::email()->preventSpoofing()->rfcCompliant(strict: true)`
 * is the recommended idiom for modern email validation. Each fluent method
 * returns `$this` on `Rules\Email`, so the analyzer walks the MethodCall
 * chain down to the root StaticCall and resolves the escape from the Email
 * class. Header and cookie taint are escaped; SSRF is not.
 */
final class FluentChainedEmailRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'team_email' => [
                'required',
                'string',
                Rule::email()->preventSpoofing()->rfcCompliant(strict: true),
            ],
        ];
    }
}

function direct(FluentChainedEmailRequest $request): \Illuminate\Http\RedirectResponse {
    return redirect()->to($request->safe()->input('team_email'));
}
?>
--EXPECTF--
%ATaintedSSRF on line %d: Detected tainted network request
