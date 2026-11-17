--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class NamedArgumentAgeRequest extends FormRequest
{
    public function rules(): array
    {
        return ['age' => 'required|integer'];
    }
}

/**
 * The key is the first parameter of `input($key = null, $default = null)`, not the first written
 * argument: a rule's escape applies to the field named by `$key` only.
 */
function default_is_not_a_key(NamedArgumentAgeRequest $request): void
{
    // No key: the whole (unvalidated) input is read, so the 'age' escape must not apply
    echo $request->input(default: 'age');
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
InvalidArgument on line %d: Argument 1 of echo expects string, but array<string, mixed> provided
