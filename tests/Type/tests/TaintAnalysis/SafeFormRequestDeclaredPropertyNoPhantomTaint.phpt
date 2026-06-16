--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Regression guard: when a FormRequest declares a real property whose name
 * matches a rule, the value at runtime comes from PHP property
 * initialisation, NOT from `Request::__get()`. The plugin's type narrowing
 * correctly defers (FormRequestPropertyHandler::resolveRuleForProperty
 * returns null on declared properties), and the taint paths must do the
 * same — otherwise a sink that reads the declared property gets a
 * phantom `ALL_INPUT` source that has no relationship to user input.
 *
 * Pre-fix bug surfaced in #1016 review round 1:
 * ValidationTaintHandler::addTaints fired on every FormRequest property
 * fetch with a matching presence-guaranteed rule, ignoring whether the
 * user had opted out via a real declaration. A sink consuming
 * `$this->email` for a declared `public string $email` would report a
 * false-positive `TaintedHtml` even though the value never touched the
 * input bag. The shared `FormRequestPropertyHandler::resolveRuleForProperty`
 * resolver (used by both the type provider and the taint paths) closes
 * the gap by enforcing the same gate on both sides.
 *
 * Asserted: no taint reports of any kind, despite the rule for `email`
 * being present and the value flowing into two sinks (header + echo).
 */
final class DeclaredEmailRequest extends FormRequest
{
    /** Declared by the user — opts out of magic-read narrowing. */
    public string $email = 'static@example.com';

    public function rules(): array
    {
        // Rule for the same key — exercises the gate divergence guard.
        return ['email' => ['required', 'email']];
    }

    public function sendHeader(): void
    {
        // Header sink reading the declared property — must NOT report.
        \header('X-User: ' . $this->email);
    }

    public function renderHtml(): void
    {
        // HTML sink reading the declared property — must NOT report.
        echo $this->email;
    }
}

function dispatch(DeclaredEmailRequest $request): void {
    $request->sendHeader();
    $request->renderHtml();
}
?>
--EXPECTF--
