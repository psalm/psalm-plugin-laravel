--ARGS--
--threads=1 --no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Rules;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Variable-binding variant of SafeInlineValidateCustomRuleEscapesHeader.
 *
 * The repro from #834: the rule's @psalm-taint-escape header bit must
 * survive when the input() return value is bound to a local variable
 * before the sink read. TaintedSSRF still fires (DNS resolution of a
 * valid domain can still hit an internal host); TaintedHeader must not.
 *
 * The --threads=1 in --ARGS-- is a deliberate workaround. PsalmTester
 * runs all .phpt files in one Psalm invocation; with the default thread
 * pool, the parallel-worker graph merge appears to drop the rule's
 * removed_taints on the variable-indirection edge, even though isolated
 * Psalm runs report the correct flow. Pinning to one thread keeps the
 * test deterministic. The plugin code itself is unaffected.
 *
 * @psalm-taint-escape header
 * @psalm-taint-escape cookie
 */
final class InlineDnsRuleViaVariable implements ValidationRule
{
    #[\Override]
    public function validate(string $attribute, mixed $value, \Closure $fail): void {}
}

/**
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedArgument
 */
function storeViaVariable(Request $request): RedirectResponse {
    $request->validate([
        'contact_email' => ['required', 'string', new InlineDnsRuleViaVariable()],
    ]);

    $boundEmail834 = $request->input('contact_email');

    return redirect()->to($boundEmail834);
}
?>
--EXPECTF--
TaintedSSRF on line %d: Detected tainted network request
