--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Rules;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * OR-merge regression: two inline `validate()` calls on the same variable
 * mention the SAME field with DIFFERENT escape bitmasks. The collector
 * must OR the bits so the value is safe for every kind either rule
 * escapes. If the merge accidentally becomes AND (intersection), or if
 * the second call overwrites the first, at least one of the two assertions
 * below would fail.
 *
 *   Rule A -> escapes `header` only
 *   Rule B -> escapes `sql`    only
 *   Expected merged bits: header | sql
 *
 * Both rules must pass for control to reach the input() reads, so the
 * value satisfies both rule sets — OR is the sound merge.
 *
 *   redirect()->to($v) sinks header+ssrf -> only TaintedSSRF fires (A gave us header)
 *   DB::select($v)     sinks sql          -> clean                 (B gave us sql)
 *
 * A mutation replacing | with & at the merge site would drop one bit and
 * surface a TaintedHeader or TaintedSql; an overwrite-last-wins merge
 * would drop the FIRST bit (header). Either regression is caught.
 *
 * @psalm-taint-escape header
 */
final class HeaderOnlyMergeRule implements ValidationRule
{
    #[\Override]
    public function validate(string $attribute, mixed $value, \Closure $fail): void {}
}

/**
 * @psalm-taint-escape sql
 */
final class SqlOnlyMergeRule implements ValidationRule
{
    #[\Override]
    public function validate(string $attribute, mixed $value, \Closure $fail): void {}
}

/** @psalm-suppress MixedArgument */
function storeMergeSameKey(Request $request): RedirectResponse {
    $request->validate(['field' => ['required', new HeaderOnlyMergeRule()]]);
    $request->validate(['field' => ['required', new SqlOnlyMergeRule()]]);

    DB::select($request->input('field'));

    return redirect()->to($request->input('field'));
}
?>
--EXPECTF--
TaintedSSRF on line %d: Detected tainted network request
