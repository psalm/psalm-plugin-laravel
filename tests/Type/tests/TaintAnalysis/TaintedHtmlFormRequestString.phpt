--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Positive: string('body') with 'string' rule retains HTML taint.
 * The 'string' rule only constrains the value to be a string — arbitrary
 * content can still carry an injection payload, so no taint kind is escaped.
 *
 * Regression guard for the stub-location fix: proves taint actually flows
 * from InteractsWithData::string() through to the echo sink (complements
 * SafeFormRequestIntegerNoTaint.phpt, which asserts the 'integer' rule escape).
 */
final class BodyStringRequest extends FormRequest
{
    public function rules(): array
    {
        return ['body' => 'required|string'];
    }
}

function render(BodyStringRequest $request): void {
    echo $request->string('body');
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML
%ATaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
