--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Negative: input('key', $default) with a default expression must NOT apply
 * the rule's escape. The rule describes the validated value; the default is
 * an independent expression that can carry its own taint.
 *
 * Here 'age' is constrained to integer (would normally escape ALL_INPUT), but
 * the tainted $default comes from an unvalidated source ('fallback'), so the
 * echo sink must still fire TaintedHtml.
 *
 * The single echo is one sink, so each finding kind is reported once (not once per source path
 * into the same sink).
 */
final class InputWithDefaultRequest extends FormRequest
{
    public function rules(): array
    {
        return ['age' => 'required|integer'];
    }
}

function render(InputWithDefaultRequest $request): void {
    /** @psalm-suppress MixedArgument */
    echo $request->input('age', $request->input('fallback'));
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
