--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The `input` alias in TaintKind::TAINT_NAMES maps to ALL_INPUT. A class-level
 * `@psalm-taint-escape input` must cover every input-family taint kind,
 * including `html` — so echoing the validated value into HTML is safe.
 *
 * @psalm-taint-escape input
 */
final class AllInputRule implements ValidationRule
{
    #[\Override]
    public function validate(string $attribute, mixed $value, \Closure $fail): void {}
}

final class AllInputRequest extends FormRequest
{
    public function rules(): array
    {
        return ['field' => ['required', 'string', new AllInputRule()]];
    }
}

function render(AllInputRequest $request): void {
    echo $request->string('field');
}
?>
--EXPECTF--

