--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PHP method names are case-insensitive. The validation return-type handler
 * already receives lowercase method names from Psalm, but the v4.14 taint
 * resolver originally inspected the source spelling and missed this shape.
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
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
