--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** validated('age') with integer rule removes taint — numeric value cannot inject. */
class AgeRequest extends FormRequest
{
    public function rules(): array
    {
        return ['age' => 'required|integer'];
    }
}

function renderAge(AgeRequest $request): void {
    echo $request->validated('age');
}
?>
--EXPECTF--
%AMixedArgument on line %d: %s
