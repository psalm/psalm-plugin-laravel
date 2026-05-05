--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Variadic-form sibling of TaintedHtmlFormRequestRuleNotInNoEscape.
 *
 * After issue #873, Rule::notIn('a', 'b') routes through the 'not_in:a,b'
 * string-segment path instead of the previous class:Rules\NotIn segment.
 * The two paths must agree: `not_in` carries no escape (a blocklist does
 * not constrain accepted values), so HTML/quotes taints continue to flow
 * to the echo sink unchanged.
 */
final class FluentVariadicNotInRequest extends FormRequest
{
    public function rules(): array
    {
        return ['role' => ['required', 'string', Rule::notIn('banned', 'blocked')]];
    }
}

function render(FluentVariadicNotInRequest $request): void {
    echo $request->string('role');
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML
%ATaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
