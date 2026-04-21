--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Regression guard: str() (alias of string()) participates in KEYED_ACCESSOR_METHODS.
 * With 'integer' rule the escape clears all input taint.
 */
final class StrIntegerRequest extends FormRequest
{
    public function rules(): array
    {
        return ['age' => 'required|integer'];
    }
}

function render(StrIntegerRequest $request): void {
    echo $request->str('age');
}
?>
--EXPECTF--
