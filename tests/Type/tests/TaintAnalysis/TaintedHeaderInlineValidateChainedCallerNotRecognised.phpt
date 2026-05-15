--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Chained / property-access callers like `$container->getRequest()->validate(...)`
 * or `$this->service->validate(...)` are deliberately not tracked — the
 * caller is not a plain `Variable`, so there's no source-level name to key
 * the cache by. Locks the bail-out on the `Variable` check in the collector.
 *
 * A plain `$request->validate([...])` on the same function would apply the
 * escape; here the chained form does not, so the subsequent
 * `$request->input('contact_email')` still reports TaintedHeader.
 */
final class RequestFactory
{
    public function make(): Request
    {
        return new Request();
    }
}

/** @psalm-suppress MixedArgument */
function storeChained(RequestFactory $factory, Request $request): RedirectResponse
{
    // Chained caller: `$factory->make()` returns a Request, but the method
    // call is not `$variable->validate(...)`. Collector bails on the
    // `$expr->var instanceof Variable` check.
    $factory->make()->validate(['contact_email' => 'required|email']);

    return redirect()->to($request->input('contact_email'));
}
?>
--EXPECTF--
TaintedHeader on line %d: Detected tainted header
TaintedSSRF on line %d: Detected tainted network request
