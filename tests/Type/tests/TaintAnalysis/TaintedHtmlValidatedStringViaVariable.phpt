--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Known limitation: when ValidatedTypeHandler provides a return type,
 * Psalm skips the stub's @psalm-taint-source. Taint is lost through
 * variable assignment. Per "silence over false positives" principle,
 * this is acceptable — we don't report issues we're not certain about.
 *
 * Direct usage (echo $request->validated('body')) IS detected.
 * @see TaintedHtmlValidatedString.phpt
 */
class BodyRequest extends FormRequest
{
    public function rules(): array
    {
        return ['body' => 'required|string'];
    }
}

function renderViaVariable(BodyRequest $request): void {
    $body = $request->validated('body');
    echo $body; // No taint reported — known limitation
}
?>
--EXPECTF--
