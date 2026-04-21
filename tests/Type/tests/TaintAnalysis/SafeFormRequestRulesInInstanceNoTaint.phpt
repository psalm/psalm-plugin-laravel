--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\In;

/**
 * `new Rules\In([...])` is the object equivalent of the 'in:a,b,c' string
 * rule — the value is bounded to a whitelist, so every input taint is safe
 * to remove. Echoing into HTML is therefore untainted.
 */
final class ObjectInRequest extends FormRequest
{
    public function rules(): array
    {
        return ['role' => ['required', 'string', new In(['admin', 'user', 'guest'])]];
    }
}

function render(ObjectInRequest $request): void {
    echo $request->string('role');
}
?>
--EXPECTF--
