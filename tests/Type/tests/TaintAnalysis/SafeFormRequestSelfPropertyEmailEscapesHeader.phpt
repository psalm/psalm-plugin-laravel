--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Regression guard for issue #1016: `$this->email` magic property reads on
 * a FormRequest narrow via FormRequestPropertyHandler, and the paired taint
 * paths in ValidationTaintHandler must:
 *
 *   1. Re-source ALL_INPUT. FormRequestPropertyHandler's `doesPropertyExist()`
 *      claims the fetch before Psalm ever reaches `Request::__get`, so that
 *      stub's own `@psalm-taint-source` (added for bare Request reads in
 *      #1301) never applies here. Without the manual re-source, the
 *      property handler's Union would leak the input pool with no taint
 *      at all.
 *   2. Apply the rule's `removedTaints` mask so the same per-rule escape
 *      that fires on `$this->input('email')` also fires on `$this->email`.
 *
 * Mirrors {@see SafeFormRequestSelfInputEmailEscapesHeader.phpt} for the
 * method-call shape so a regression in the property path can't masquerade
 * as a generic taint engine change.
 *
 * Asserted:
 *   - Header sink: NO TaintedHeader. The `email` rule escapes
 *     INPUT_HEADER, so the read is safe for `header()`.
 *   - HTML sink: TaintedHtml fires. The email rule does NOT escape
 *     html taint, so the resourced ALL_INPUT reaches `echo` end-to-end.
 */
final class SelfPropertyTaintEmailRequest extends FormRequest
{
    public function rules(): array
    {
        return ['email' => ['required', 'email']];
    }

    /** Header sink — must NOT report TaintedHeader. */
    public function sendUserHeader(): void
    {
        \header('X-User: ' . $this->email);
    }

    /** HTML sink — MUST report TaintedHtml. */
    public function renderUserName(): void
    {
        echo $this->email;
    }
}

function dispatch(SelfPropertyTaintEmailRequest $request): void {
    $request->sendUserHeader();
    $request->renderUserName();
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
