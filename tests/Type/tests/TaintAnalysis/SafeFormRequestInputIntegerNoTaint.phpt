--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Regression guard: $req->input('key') on a typed FormRequest goes through
 * the direct-FormRequest resolution path (distinct from safe()->input()).
 * With 'integer' rule the escape clears all input taint.
 *
 * The asserted behavior is taint: when the rule is 'integer', no Tainted*
 * error must fire even though echo is an html/quotes sink. The
 * `'integer'` rule used to leave the return type as `mixed` here; since
 * ValidatedTypeHandler also narrows `$req->input('age')` to
 * `int|numeric-string` on a required field, no MixedArgument suppression
 * is needed and the taint-escape behaviour is unchanged.
 */
final class DirectInputAgeRequest extends FormRequest
{
    public function rules(): array
    {
        return ['age' => 'required|integer'];
    }
}

function render(DirectInputAgeRequest $request): void {
    echo $request->input('age');
}
?>
--EXPECTF--
