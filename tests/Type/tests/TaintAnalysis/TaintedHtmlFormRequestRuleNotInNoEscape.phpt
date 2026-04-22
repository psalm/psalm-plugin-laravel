--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `Rule::notIn()` maps to `Rules\NotIn`, which is deliberately absent from
 * the first-party escape table: rejecting a blocklist of values does not
 * constrain the accepted value to a safe shape. The taint therefore flows
 * unchanged into echo, so TaintedHtml (and TaintedTextWithQuotes) fire.
 */
final class FluentNotInRequest extends FormRequest
{
    public function rules(): array
    {
        return ['role' => ['required', 'string', Rule::notIn(['banned'])]];
    }
}

function render(FluentNotInRequest $request): void {
    echo $request->string('role');
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML
%ATaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
