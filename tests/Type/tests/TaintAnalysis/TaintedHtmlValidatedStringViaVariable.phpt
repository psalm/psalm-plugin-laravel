--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The stub's @psalm-taint-source on validated() survives ValidatedTypeHandler
 * narrowing the return type, so the taint follows the value through a variable.
 *
 * @see TaintedHtmlValidatedString.phpt
 */
class BodyRequest extends FormRequest
{
    public function rules(): array
    {
        return ['body' => 'required|string'];
    }
}

function renderViaVariable(BodyRequest $request): void {
    $body = $request->validated('body');
    echo $body;
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
