--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * safe()->input('body') — rule is 'string', so no per-kind taint is escaped.
 * ValidationTaintHandler compensates for the type-provider override by re-adding
 * the taint source, so the echo sink still fires.
 */
class SafeRequest extends FormRequest
{
    public function rules(): array
    {
        return ['body' => 'required|string'];
    }
}

function renderSafeInput(SafeRequest $request): void {
    echo $request->safe()->input('body');
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML
%ATaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
