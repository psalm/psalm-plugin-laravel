--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Regression guard: $this->input('key') inside a FormRequest method narrows
 * via ValidatedTypeHandler::resolveSelfInput(), which silences the stub's
 * @psalm-taint-source. ValidationTaintHandler::addTaints() must re-source
 * ALL_INPUT for the call to remain detectable as user-controlled, and the
 * 'email' rule's header/cookie escape must still apply via the keyed-accessor
 * escape path in ValidationTaintHandler::removeTaints.
 *
 * Asserted:
 *  - Header sink on the email value does NOT report (escape covered it).
 *  - HTML sink on the same value DOES report TaintedHtml (taint flows).
 */
final class SelfTaintEmailRequest extends FormRequest
{
    public function rules(): array
    {
        return ['email' => ['required', 'email']];
    }

    #[\Override]
    public function prepareForValidation(): void
    {
        // header sink — the 'email' rule's header/cookie escape clears the
        // taint kind, so no TaintedHeader is expected.
        \header('X-User: ' . $this->input('email'));

        // html sink — the 'email' rule does not escape html taint, so this
        // must surface TaintedHtml/TaintedTextWithQuotes.
        echo $this->input('email');
    }
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
