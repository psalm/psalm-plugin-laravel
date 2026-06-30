--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Case-insensitivity guard for the inline-validate escape binding
 * (InlineValidateRulesCollector::resolveEscapeFromAccessorRhs). PHP method
 * dispatch is case-insensitive, so `$v = $request->STRING('age')` must bind
 * the inline rule's escape to $v exactly as the lowercase form does. The
 * `numeric` rule clears all input taint, so echoing the bound value is clean.
 *
 * This is the one escape-side case-insensitivity that is testable in
 * isolation: on the resolver path a single toLowerString() feeds both the
 * source and escape facets, so source and escape cannot diverge by casing;
 * only the inline binding decouples the escape from the source.
 *
 * Uppercase counterpart of SafeInlineValidateStringAccessorEscapesHeaderViaVariable.
 * --threads=1: see SafeInlineValidateCustomRuleEscapesHeaderViaVariable for
 * why the via-variable binding tests pin to a single Psalm thread.
 */
function renderViaUppercaseStringBinding(Request $request): void {
    $request->validate([
        'age' => ['required', Rule::numeric()],
    ]);

    $boundString = $request->STRING('age');
    echo $boundString;
}
?>
--EXPECTF--
