--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * safe()->input('age') respects the rule-based taint escape the same as
 * validated('age'): ValidatedInput<TRequest> carries the FormRequest class
 * in its generic parameter. The 'integer' rule escapes all input taint.
 */
final class SafeInputAgeRequest extends FormRequest
{
    public function rules(): array
    {
        return ['age' => 'required|integer'];
    }
}

function render(SafeInputAgeRequest $request): void {
    // input('age') is narrowed to int|numeric-string by ValidatedTypeHandler,
    // so no MixedArgument — the echo is straight int/string.
    echo $request->safe()->input('age');
}
?>
--EXPECTF--
