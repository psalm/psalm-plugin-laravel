--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Numeric;

/**
 * `new Rules\Numeric()` escapes every input taint — a numeric value cannot
 * carry injection meta-characters. Echoing the validated string must not
 * report TaintedHtml.
 */
final class ObjectNumericRequest extends FormRequest
{
    public function rules(): array
    {
        return ['age' => ['required', 'string', new Numeric()]];
    }
}

function render(ObjectNumericRequest $request): void {
    echo $request->string('age');
}
?>
--EXPECTF--
