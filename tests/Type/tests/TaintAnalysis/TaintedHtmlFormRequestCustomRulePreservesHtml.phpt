--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The class-level @psalm-taint-escape only escapes the kinds declared.
 * Echoing the value into HTML must still report TaintedHtml — escaping
 * `header`/`cookie` does not imply safety for other sinks.
 *
 * @psalm-taint-escape header
 * @psalm-taint-escape cookie
 */
final class PartialEscapeRule implements ValidationRule
{
    #[\Override]
    public function validate(string $attribute, mixed $value, \Closure $fail): void {}
}

final class ContactRequestHtml extends FormRequest
{
    public function rules(): array
    {
        return ['team_email' => ['required', 'string', new PartialEscapeRule()]];
    }
}

function render(ContactRequestHtml $request): void {
    echo $request->string('team_email');
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
