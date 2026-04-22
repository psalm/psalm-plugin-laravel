--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Fluent `Rule::in([...])` resolves to `Rules\In`, which escapes all input
 * taint because the accepted set is bounded by the constructor whitelist.
 */
final class FluentInRequest extends FormRequest
{
    public function rules(): array
    {
        return ['role' => ['required', 'string', Rule::in(['admin', 'user', 'guest'])]];
    }
}

function render(FluentInRequest $request): void {
    echo $request->string('role');
}
?>
--EXPECTF--
