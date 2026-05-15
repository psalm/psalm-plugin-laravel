--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Behavior contract: the conditional form of @psalm-taint-escape (e.g.
 * `(header)`) is parameter-scoped and has no meaning on a class. This rule
 * must contribute zero escape bits, so HTML taint from the validated value
 * still flows through the echo sink.
 *
 * Note: this locks in observable behavior, not a specific code branch — the
 * `$kind[0] === '('` early-continue and the `TAINT_NAMES[$kind] ?? 0`
 * fallback both produce the same outcome here, and the test doesn't
 * distinguish between them.
 *
 * @psalm-taint-escape (header)
 */
final class ConditionalEscapeRule implements ValidationRule
{
    #[\Override]
    public function validate(string $attribute, mixed $value, \Closure $fail): void {}
}

final class ConditionalRequest extends FormRequest
{
    public function rules(): array
    {
        return ['field' => ['required', 'string', new ConditionalEscapeRule()]];
    }
}

function render(ConditionalRequest $request): void {
    echo $request->string('field');
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
