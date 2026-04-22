--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `Rule::unique(...)` is intentionally absent from the Rule facade method
 * map: its value is constrained by a DB lookup rather than a character
 * shape, so it carries no taint escape. The field must still flow taint
 * (TaintedHtml fires) and, critically, must remain present in the rules
 * map so the 'string' rule's type inference still applies.
 */
final class FluentUniqueRequest extends FormRequest
{
    public function rules(): array
    {
        return ['email' => ['required', 'string', Rule::unique('users', 'email')]];
    }
}

function render(FluentUniqueRequest $request): void {
    echo $request->string('email');
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML
%ATaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
