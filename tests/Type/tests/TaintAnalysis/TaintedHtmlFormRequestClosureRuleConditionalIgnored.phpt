--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Behavior contract: the conditional form of `@psalm-taint-escape` is
 * parameter-scoped and is ignored on a closure rule, the same as on a Rule
 * class. The closure must contribute zero escape bits, so HTML taint from the
 * validated value still flows through the echo sink.
 *
 * The conditional is spelled in the well-formed form Psalm accepts on a
 * function-like, so the only thing under test is the plugin ignoring it.
 */
final class ConditionalClosureRequest extends FormRequest
{
    public function rules(): array
    {
        return ['field' => ['required', 'string',
            /** @psalm-taint-escape ($value is string ? 'html' : null) */
            function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value === $attribute) {
                    $fail('invalid');
                }
            },
        ]];
    }
}

function render(ConditionalClosureRequest $request): void {
    echo $request->string('field');
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
