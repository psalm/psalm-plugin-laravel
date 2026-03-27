--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Known limitation: safe()->input('body') does not propagate taint.
 * The ValidatedTypeHandler provides a return type for input(), which causes
 * Psalm to skip the stub's @psalm-taint-source annotation on ValidatedInput::input().
 * Same root cause as the validated() variable assignment limitation.
 * TODO: if https://github.com/vimeo/psalm/issues/11765 is fixed, this test should expect TaintedHtml.
 */
class SafeRequest extends FormRequest
{
    public function rules(): array
    {
        return ['body' => 'required|string'];
    }
}

function renderSafeInput(SafeRequest $request): void {
    echo $request->safe()->input('body'); // No taint reported — known limitation
}
?>
--EXPECTF--
