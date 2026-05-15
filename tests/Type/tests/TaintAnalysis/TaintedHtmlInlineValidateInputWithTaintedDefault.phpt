--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Rules;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Request;

/**
 * `$request->input('key', $default)` — when a second argument is supplied,
 * the value returned may be the default expression rather than the
 * validated field, so the rule's escape must not apply. The default here
 * is a different tainted source; echoing the result must still report
 * TaintedHtml. Mirrors TaintedHtmlInputWithTaintedDefault for the inline
 * validate path.
 *
 * @psalm-taint-escape input
 */
final class InlineAllInputRule implements ValidationRule
{
    #[\Override]
    public function validate(string $attribute, mixed $value, \Closure $fail): void {}
}

/** @psalm-suppress MixedArgument */
function renderWithDefault(Request $request): void {
    $request->validate([
        'field' => ['required', 'string', new InlineAllInputRule()],
    ]);

    // Second arg makes the result "validated value or default" — default
    // carries its own taint source.
    echo $request->input('field', $request->input('untrusted'));
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
