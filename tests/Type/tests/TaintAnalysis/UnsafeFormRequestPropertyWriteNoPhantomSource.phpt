--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Regression guard for the assignment-LHS case after the #1022-review
 * refactor removed the explicit LHS-tracking machinery
 * (`$assignmentLhsPropertyFetchIds` + the `BeforeExpressionAnalysis` hook).
 *
 * Writing to an undeclared, rule-covered magic property is already a Psalm
 * error (`Request` has no `__set`, so the assignment is
 * `UndefinedPropertyAssignment`). Without the old guard, `addTaints` now also
 * fires on the LHS PropertyFetch node — this test pins that doing so produces
 * NO phantom source: Psalm is not field-sensitive on arbitrary objects, so the
 * sourced write-target has no edge to a sink and emits no extra report.
 *
 * The subsequent READ (`echo $req->email`) re-sources `ALL_INPUT` exactly once
 * and reaches the HTML sink — the expected, single taint report. A declared
 * `public string $email` would instead opt out of narrowing entirely (see
 * SafeFormRequestDeclaredPropertyNoPhantomTaint.phpt); this case covers the
 * undeclared-write path the removed machinery used to guard.
 *
 * Asserted:
 *   - one `UndefinedPropertyAssignment` on the write (pre-existing Psalm
 *     behaviour, not introduced by the plugin);
 *   - exactly one `TaintedHtml` from the read, with NO duplicate and NO
 *     spurious write-sourced report.
 */
final class PropertyWriteProbeRequest extends FormRequest
{
    public function rules(): array
    {
        return ['email' => ['required', 'email']];
    }
}

function writeThenRead(PropertyWriteProbeRequest $req): void {
    // RHS is a literal, so it carries no taint of its own — any TaintedHtml
    // below must come from the read's re-source, never from this write.
    $req->email = 'literal';
    echo $req->email;
}
?>
--EXPECTF--
UndefinedPropertyAssignment on line %d: Instance property App\Http\Requests\PropertyWriteProbeRequest::$email is not defined
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
