--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Fluent `Rule::numeric()` resolves to `Rules\Numeric`, which escapes all
 * input taint. Echoing the validated string into HTML must not report
 * TaintedHtml.
 */
final class FluentNumericRequest extends FormRequest
{
    public function rules(): array
    {
        return ['age' => ['required', 'string', Rule::numeric()]];
    }
}

function render(FluentNumericRequest $request): void {
    echo $request->string('age');
}
?>
--EXPECTF--
