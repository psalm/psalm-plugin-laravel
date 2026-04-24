--ARGS--
--threads=1 --no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * KNOWN LIMITATION: the variable-binding cache is populated by an
 * AST-only check in `beforeExpressionAnalysis` that requires a literal
 * `String_` node for the accessor key. A constant reference like
 * `$request->input(self::KEY)` is not resolved — `beforeExpressionAnalysis`
 * runs before the RHS type inference that the sibling MethodCall path
 * uses to unwrap constants. Fail-safe: the binding keeps the original
 * taint, so both TaintedHeader and TaintedSSRF fire.
 *
 * The inline form (`$request->input(self::KEY)` used directly in a sink
 * call) still benefits from the rule's escape via the existing
 * ArgumentAnalyzer dispatch; only the variable-bound form loses it.
 *
 * This test locks in the current behaviour so any future enhancement
 * (e.g. populating from `afterExpressionAnalysis` with resolved types
 * for downstream use sites) is a deliberate, reviewed change.
 *
 * --threads=1: see SafeInlineValidateCustomRuleEscapesHeaderViaVariable
 * for why these tests pin to a single Psalm thread.
 */
final class KeyNames
{
    public const EMAIL = 'email_field';
}

/**
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedArgument
 */
function constantKeyProbe(Request $request): RedirectResponse {
    $request->validate([KeyNames::EMAIL => 'email']);

    $bound = $request->input(KeyNames::EMAIL);

    return redirect()->to($bound);
}
?>
--EXPECTF--
TaintedHeader on line %d: Detected tainted header
TaintedSSRF on line %d: Detected tainted network request
