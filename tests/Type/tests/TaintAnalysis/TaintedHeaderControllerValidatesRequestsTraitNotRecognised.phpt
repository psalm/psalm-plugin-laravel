--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * `ValidatesRequests::validate($request, $rules)` from a controller is
 * NOT recognised by the inline-validate collector: the caller is `$this`
 * (a Controller), not a Request. This test locks the documented caveat in
 * {@see \Psalm\LaravelPlugin\Handlers\Validation\InlineValidateRulesCollector}
 * so a future widening of the caller-type check can't silently extend the
 * escape to Controllers.
 *
 * TaintedHeader and TaintedSSRF must still fire even though the `email`
 * rule would have escaped header/cookie if the call had been on $request.
 */
final class ContactController
{
    use ValidatesRequests;

    /** @psalm-suppress MixedArgument */
    public function store(Request $request): RedirectResponse
    {
        $this->validate($request, ['contact_email' => 'required|email']);

        return redirect()->to($request->input('contact_email'));
    }
}
?>
--EXPECTF--
TaintedHeader on line %d: Detected tainted header
TaintedSSRF on line %d: Detected tainted network request
