--ARGS--
--threads=1 --no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Scalar-rule variant: `'email' => 'email'` (not wildcard). Laravel
 * wraps a scalar field in a single-element array at runtime when read
 * via `array()`, so the same scalar 'email' rule should escape header
 * taint for the foreach element too.
 *
 * The rule lookup uses the literal key `'email'`; the rules cache stores
 * `'email' => emailRule` directly (no wildcard expansion needed), and
 * `lookupRuleByKey` finds it on the first try.
 *
 * --threads=1: see SafeInlineValidateCustomRuleEscapesHeaderViaVariable
 * for why these tests pin to a single Psalm thread.
 */
/**
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedArgument
 */
function storeArrayAccessorScalarRule(Request $request): RedirectResponse {
    $request->validate(['email' => 'email']);

    foreach ($request->array('email') as $email) {
        return redirect()->to($email);
    }

    return redirect()->to('/');
}
?>
--EXPECTF--
TaintedSSRF on line %d: Detected tainted network request
