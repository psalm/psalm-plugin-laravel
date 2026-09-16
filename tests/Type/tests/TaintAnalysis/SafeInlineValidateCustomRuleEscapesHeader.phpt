--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// Psalm 7.0.0-beta21 drops one of two taint flows that reach the same sink method from two
// different files in a co-analyzed batch, so this fixture's finding disappears when the suite
// runs as a whole while it is still reported on its own.
// @todo-by 2026-10-01 drop this gate once vimeo/psalm#11959 ships a fix; if the issue is still
// open then, re-check whether the batch still hides the finding and move the date.
// @see https://github.com/vimeo/psalm/issues/11959
\Tests\Psalm\LaravelPlugin\Type\PsalmVersion::skipOnRange('7.0.0-beta21', '7.0.0-beta22');
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Rules;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Inline `$request->validate([...])` with a custom Rule class carrying a
 * class-level @psalm-taint-escape. The subsequent $request->input('field')
 * must honour the same escape as the equivalent FormRequest::rules() form,
 * so redirect()->to() stays silent (TaintedHeader is removed) while the
 * http-client sink's TaintedSSRF proves the value is still tainted.
 *
 * @psalm-taint-escape header
 * @psalm-taint-escape cookie
 */
final class InlineDnsRule implements ValidationRule
{
    #[\Override]
    public function validate(string $attribute, mixed $value, \Closure $fail): void {}
}

/** @psalm-suppress MixedArgument */
function store(Request $request): RedirectResponse {
    $request->validate([
        'contact_email' => ['required', 'string', new InlineDnsRule()],
    ]);

    (new \Illuminate\Http\Client\PendingRequest())->get($request->input('contact_email'));
    return redirect()->to($request->input('contact_email'));
}
?>
--EXPECTF--
TaintedSSRF on line %d: Detected tainted network request
