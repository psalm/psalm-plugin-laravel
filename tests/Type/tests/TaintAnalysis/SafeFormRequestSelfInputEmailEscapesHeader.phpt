--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Regression guard: $this->input('key') reads on the FormRequest narrow via
 * ValidatedTypeHandler::resolveSelfInput(), which silences the stub's
 * @psalm-taint-source. ValidationTaintHandler::addTaints() must re-source
 * ALL_INPUT for the call to remain detectable as user-controlled, and the
 * `email` rule's escape must still apply via the keyed-accessor escape path
 * in ValidationTaintHandler::removeTaints.
 *
 * The sinks fire inside a custom helper that is invoked from controller
 * code AFTER Laravel's ValidatesWhenResolvedTrait has run the validator —
 * the FormRequest is resolved before the controller action executes, so
 * the email-rule escape on $this->input() is sound for this call site.
 *
 * Pre-validation hooks (prepareForValidation, withValidator) are
 * deliberately NOT exercised by a header sink: the plugin's "trust the
 * rule" trade-off makes the escape consistent with type narrowing there,
 * but a header sink in those contexts would lock in a real false-negative
 * window. The HTML sink (which the email rule does NOT escape) still
 * surfaces TaintedHtml in either context, so the positive-flow guard
 * stays representative.
 *
 * Asserted:
 *   - Header sink in a post-validation helper does NOT report: the
 *     'email' rule's INPUT_HEADER escape covered it.
 *   - HTML sink in the same context DOES report TaintedHtml +
 *     TaintedTextWithQuotes — the email rule leaves html taint intact,
 *     so flow from $this->input('email') is preserved end-to-end.
 */
final class SelfTaintEmailRequest extends FormRequest
{
    public function rules(): array
    {
        return ['email' => ['required', 'email']];
    }

    /**
     * Header sink — must NOT report TaintedHeader: the 'email' rule
     * removes INPUT_HEADER from the taint bitmask.
     */
    public function sendUserHeader(): void
    {
        \header('X-User: ' . $this->input('email'));
    }

    /**
     * HTML sink — MUST report TaintedHtml: the 'email' rule leaves
     * html taint intact, so the narrowed-and-resourced flow reaches
     * the echo.
     */
    public function renderUserName(): void
    {
        echo $this->input('email');
    }
}

function dispatch(SelfTaintEmailRequest $request): void {
    $request->sendUserHeader();
    $request->renderUserName();
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
