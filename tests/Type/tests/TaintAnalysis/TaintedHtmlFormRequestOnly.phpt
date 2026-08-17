--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Positive: FormRequest::only() returns tainted input.
 *
 * Regression guard for the trait-composition chain on FormRequest:
 * FormRequest extends Request, Request uses InteractsWithInput,
 * InteractsWithInput uses InteractsWithData (where the stub lives after #823).
 * If any link in that chain breaks, the echo below stops firing TaintedHtml.
 */
final class OnlyAccessorRequest extends FormRequest
{
    public function rules(): array
    {
        return ['body' => 'required|string'];
    }
}

function render(OnlyAccessorRequest $request): void {
    $data = $request->only(['body']);

    echo $data['body'];
}
?>
--EXPECTF--
MixedArgument on line %d: Argument 1 of echo cannot be mixed, expecting string
PossiblyUndefinedStringArrayOffset on line %d: Possibly undefined array offset ''body'' is risky given expected type 'array-key'. Consider using isset beforehand.
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
