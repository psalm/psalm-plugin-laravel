--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PHP method names are case-insensitive. The validation return-type handler
 * already receives lowercase method names from Psalm, but the v4.14 taint
 * resolver originally inspected the source spelling and missed this shape.
 *
 * The header sink is pinned at the current false positive: Psalm drops the
 * `email` rule's escape for a call nested in a concatenation.
 *
 * @todo-by https://github.com/vimeo/psalm/issues/13604 drop the TaintedHeader expectation
 */
final class UppercaseInputEmailRequest extends FormRequest
{
    public function rules(): array
    {
        return ['email' => ['required', 'email']];
    }

    public function sendUserHeader(): void
    {
        \header('X-User: ' . $this->INPUT('email'));
    }

    public function renderUserName(): void
    {
        echo $this->INPUT('email');
    }
}

function dispatch(UppercaseInputEmailRequest $request): void {
    $request->sendUserHeader();
    $request->renderUserName();
}
?>
--EXPECTF--
TaintedHeader on line %d: Detected tainted header
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
