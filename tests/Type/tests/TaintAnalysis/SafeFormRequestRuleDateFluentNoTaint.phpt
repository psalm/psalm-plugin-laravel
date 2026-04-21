--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Fluent `Rule::date()` (including format/past/future chains) resolves to
 * `Rules\Date`, whose object output always includes a 'date' or
 * 'date_format:...' constraint. That matches the string-rule 'date' escape,
 * which covers every input taint kind.
 */
final class FluentDateRequest extends FormRequest
{
    public function rules(): array
    {
        return ['born_on' => ['required', 'string', Rule::date()->beforeToday()]];
    }
}

function render(FluentDateRequest $request): void {
    echo $request->string('born_on');
}
?>
--EXPECTF--
