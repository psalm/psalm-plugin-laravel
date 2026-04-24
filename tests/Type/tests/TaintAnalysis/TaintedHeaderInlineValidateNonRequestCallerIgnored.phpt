--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Locks the `callerIsRequest` rejection branch. An unrelated class
 * happens to define its own `validate(array $rules)` method with the same
 * name; the collector must not treat it as a Request::validate call. A
 * subsequent `$request->input('contact_email')` must therefore still
 * carry TaintedHeader — nothing has validated the Request object.
 */
final class OwnValidator
{
    /** @param array<string, string> $rules */
    public function validate(array $rules): void {}
}

/** @psalm-suppress MixedArgument */
function storeUnrelatedValidator(OwnValidator $own, Request $request): RedirectResponse
{
    $own->validate(['contact_email' => 'required|email']);

    return redirect()->to($request->input('contact_email'));
}
?>
--EXPECTF--
TaintedHeader on line %d: Detected tainted header
TaintedSSRF on line %d: Detected tainted network request
