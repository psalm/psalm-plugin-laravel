--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Rules;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Cache eviction test: a `validate()` call inside a closure populates the
 * cache under the closure's own FunctionLikeAnalyzer id. When the outer
 * function later calls `$request->input()`, the lookup is keyed by the
 * outer function-like id — nothing matches, so the escape does not apply
 * and TaintedHeader must still fire.
 *
 * This guards the "closures are separate scopes" design decision
 * documented in InlineValidateRulesCollector.
 *
 * @psalm-taint-escape header
 * @psalm-taint-escape cookie
 */
final class ClosureBoundaryRule implements ValidationRule
{
    #[\Override]
    public function validate(string $attribute, mixed $value, \Closure $fail): void {}
}

/** @psalm-suppress MixedArgument */
function store(Request $request): RedirectResponse {
    $runValidation = static function (Request $r): void {
        $r->validate([
            'contact_email' => ['required', 'string', new ClosureBoundaryRule()],
        ]);
    };

    $runValidation($request);

    return redirect()->to($request->input('contact_email'));
}
?>
--EXPECTF--
TaintedHeader on line %d: Detected tainted header
TaintedSSRF on line %d: Detected tainted network request
