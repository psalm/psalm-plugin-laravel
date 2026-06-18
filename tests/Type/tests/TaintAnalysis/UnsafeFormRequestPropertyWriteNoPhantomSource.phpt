--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Regression guard for the assignment-LHS case: `addTaints` fires on the LHS
 * PropertyFetch node of `$req->email = ...` and must NOT create a phantom taint
 * source that reaches a sink.
 *
 * Psalm 6 vs 7 behavioural note (kept divergent from master deliberately):
 * on Psalm 6 a literal write to a magic property makes the *subsequent* read
 * field-sensitive — `echo $req->email` after `$req->email = 'literal'` returns
 * the assigned literal, so the provider re-source is dropped and the read is
 * clean. Psalm 6 also does not emit `UndefinedPropertyAssignment` for this
 * write (unlike Psalm 7). Either way the LHS write produces no phantom source,
 * which is exactly what this guards: `writeThenRead` must stay completely
 * silent. If a stray write-side source ever connected to the echo sink, a
 * `TaintedHtml` would surface there and fail this test.
 *
 * `readFresh` then pins the positive path the feature exists for: a plain
 * `echo $req->email` (no preceding write) re-sources `ALL_INPUT` once and
 * reaches the HTML sink. A declared `public string $email` would instead opt
 * out of narrowing entirely (see SafeFormRequestDeclaredPropertyNoPhantomTaint.phpt).
 */
final class PropertyWriteProbeRequest extends FormRequest
{
    public function rules(): array
    {
        return ['email' => ['required', 'email']];
    }
}

function writeThenRead(PropertyWriteProbeRequest $req): void {
    // RHS is a literal carrying no taint. On Psalm 6 the read below is narrowed
    // to that literal, so this function is expected to emit nothing — proving
    // the LHS write created no phantom source.
    $req->email = 'literal';
    echo $req->email;
}

function readFresh(PropertyWriteProbeRequest $req): void {
    // No preceding write: the magic-property read re-sources ALL_INPUT and hits
    // the HTML sink exactly once.
    echo $req->email;
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
