--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * string('age') with 'integer' rule removes all input taint —
 * an integer value cannot carry an injection payload. Escape applies
 * to FormRequest accessor methods, not just validated().
 */
final class InputAgeRequest extends FormRequest
{
    public function rules(): array
    {
        return ['age' => 'required|integer'];
    }
}

function render(InputAgeRequest $request): void {
    echo $request->string('age');
}
?>
--EXPECTF--
