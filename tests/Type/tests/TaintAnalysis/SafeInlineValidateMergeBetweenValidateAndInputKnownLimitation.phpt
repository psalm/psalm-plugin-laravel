--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * KNOWN LIMITATION — `$request->merge([...])` laundering.
 *
 * Laravel's `$request->validate([...])` returns the validated snapshot
 * but does NOT write it back into the Request's input bag — `input()` and
 * `all()` keep reading the live bag. A `merge()` between `validate()` and
 * `input()` therefore overwrites the rule-covered key with raw data, but
 * the collector's cache still carries the original rule's escape, so
 * `input('contact_email')` is read as safe even though the value is now
 * attacker-controlled.
 *
 * This is the same shape of unsoundness as the documented FormRequest
 * caveat about a subclass `passedValidation()` calling `$this->merge(...)`
 * on a rule-covered key, but it happens in the controller body rather
 * than in a FormRequest subclass. Prefer `validate()`'s return value or
 * `$request->validated()` on a FormRequest for security-sensitive reads
 * after a `merge()`.
 *
 * This test locks the current behaviour so a future tightening (e.g.
 * invalidating the cache entry on merge() calls) is a deliberate,
 * reviewed change.
 */
/** @psalm-suppress MixedArgument */
function laundered(Request $request, string $rawAttackerHeader): RedirectResponse {
    $request->validate(['contact_email' => 'required|email']);

    // merge() overwrites the validated value with un-revalidated input —
    // the cache still applies the 'email' rule's header escape.
    $request->merge(['contact_email' => $rawAttackerHeader]);

    return redirect()->to($request->input('contact_email'));
}
?>
--EXPECTF--
TaintedSSRF on line %d: Detected tainted network request
