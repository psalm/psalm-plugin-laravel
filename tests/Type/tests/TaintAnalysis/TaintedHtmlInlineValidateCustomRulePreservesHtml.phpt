--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Rules;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Request;

/**
 * Partial-escape mirror of the FormRequest test of the same shape: the
 * class-level @psalm-taint-escape only removes the declared kinds. Echoing
 * the inline-validated value into HTML must still report TaintedHtml —
 * escaping `header`/`cookie` implies nothing about HTML safety.
 *
 * @psalm-taint-escape header
 * @psalm-taint-escape cookie
 */
final class PartialEscapeInlineRule implements ValidationRule
{
    #[\Override]
    public function validate(string $attribute, mixed $value, \Closure $fail): void {}
}

/** @psalm-suppress MixedArgument */
function render(Request $request): void {
    $request->validate([
        'bio' => ['required', 'string', new PartialEscapeInlineRule()],
    ]);

    echo $request->input('bio');
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
