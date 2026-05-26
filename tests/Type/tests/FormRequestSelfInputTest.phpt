--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Type narrowing for $this->input('field') calls inside a FormRequest subclass.
 *
 * The plugin narrows $request->validated('field') / $request->safe()->input('field')
 * from controller code. ValidatedTypeHandler::resolveSelfInput() extends the same
 * rule-driven narrowing to $this->input(...) on the FormRequest itself, e.g. inside
 * prepareForValidation(), passedValidation(), withValidator(), or a custom helper.
 *
 * Soundness gate: only presence-guaranteed fields (required / present / accepted /
 * declined, and only when `sometimes` is absent) are narrowed. Optional fields
 * (sometimes, no required-style rule, conditional required_if / present_with
 * siblings, or fields not in rules()) fall through to the InteractsWithInput
 * stub's `mixed` return.
 *
 * Note: narrowing in `prepareForValidation()` is technically unsound — input()
 * reads the raw request data before the validator runs, so a `required|string`
 * rule does not guarantee the value is a string yet. This trade-off mirrors the
 * `validated()` design choice ("Option 1" in #1015): trust the rule for static
 * analysis precision. The `sometimes`/non-required gate keeps the unsoundness
 * scoped to fields the developer has explicitly asserted will be present.
 *
 * See issue #1015.
 */
class SelfEmailRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // required-style: presence-guaranteed, narrow.
            'email' => ['required', 'email'],
            'age' => ['required', 'integer'],
            'role' => ['required', 'in:admin,user,guest'],
            // present / accepted / declined: also presence-guaranteed.
            'comment' => ['present', 'string'],
            'terms' => ['accepted'],
            'no_marketing' => ['declined'],
            // Soundness bail-outs: each of these must stay mixed.
            'nickname' => ['sometimes', 'string'],
            'biography' => ['nullable', 'string'],
            'optional_email' => ['sometimes', 'required', 'email'],
            'spouse_name' => ['required_if:has_spouse,yes', 'string'],
            // Dotted nesting: required on the leaf narrows the leaf read.
            'profile.city' => ['required', 'string'],
        ];
    }

    #[\Override]
    public function prepareForValidation(): void
    {
        // Required + type rule — narrows.
        $_email = $this->input('email');
        /** @psalm-check-type-exact $_email = string */

        $_age = $this->input('age');
        /** @psalm-check-type-exact $_age = int|numeric-string */

        $_role = $this->input('role');
        /** @psalm-check-type-exact $_role = 'admin'|'guest'|'user' */

        // present / accepted / declined — also narrow.
        $_comment = $this->input('comment');
        /** @psalm-check-type-exact $_comment = string */

        $_terms = $this->input('terms');
        /** @psalm-check-type-exact $_terms = '1'|'on'|'true'|'yes'|1|true */

        $_no_marketing = $this->input('no_marketing');
        /** @psalm-check-type-exact $_no_marketing = '0'|'false'|'no'|'off'|0|false */

        // sometimes alone → not presence-guaranteed, stay mixed.
        $_nickname = $this->input('nickname');
        /** @psalm-check-type-exact $_nickname = mixed */

        // nullable without required → no presence guarantee.
        $_biography = $this->input('biography');
        /** @psalm-check-type-exact $_biography = mixed */

        // sometimes + required: Laravel's "if present, must be valid" semantics
        // mean the field may still be absent. Narrowing would be unsound.
        $_optional_email = $this->input('optional_email');
        /** @psalm-check-type-exact $_optional_email = mixed */

        // Conditional presence (required_if, required_with, present_if, accepted_if, …)
        // is runtime-dependent — does not flip `required` in ResolvedRule.
        $_spouse_name = $this->input('spouse_name');
        /** @psalm-check-type-exact $_spouse_name = mixed */

        // Field not in rules() — stays mixed.
        $_unknown = $this->input('unknown');
        /** @psalm-check-type-exact $_unknown = mixed */

        // Default-value form: union of rule type and default expression type,
        // matching the validated($key, $default) behaviour.
        $_emailWithDefault = $this->input('email', 'fallback@example.com');
        /** @psalm-check-type-exact $_emailWithDefault = string */

        // Dotted nesting: required leaf narrows the leaf read.
        $_city = $this->input('profile.city');
        /** @psalm-check-type-exact $_city = string */
    }

    public function customWithDynamicKey(string $key): mixed
    {
        // Non-literal key falls through to the stub's mixed return — the
        // handler only narrows when the field name is a single string literal.
        $_dynamic = $this->input($key);
        /** @psalm-check-type-exact $_dynamic = mixed */

        return $_dynamic;
    }

    #[\Override]
    public function passedValidation(): void
    {
        // Same narrowing applies in every lifecycle hook on the subclass.
        $_email = $this->input('email');
        /** @psalm-check-type-exact $_email = string */
    }

    public function customHelper(): string
    {
        // Custom helpers on the FormRequest get the same narrowing.
        $_role = $this->input('role');
        /** @psalm-check-type-exact $_role = 'admin'|'guest'|'user' */

        return $_role;
    }
}

/**
 * Inherited rules(): a child subclass with no own rules() falls back to the
 * parent's rules() — `getRulesForFormRequest()` walks `parent_class` in
 * ValidationRuleAnalyzer::extractRulesFromClass(). Locks in that
 * `getCalledFqClasslikeName()` correctly resolves to the child subclass at
 * the call site, and the walk hits the parent's `rules()` method.
 */
class ChildEmailRequest extends SelfEmailRequest
{
    public function customChildAccessor(): string
    {
        $_email = $this->input('email');
        /** @psalm-check-type-exact $_email = string */

        return $_email;
    }
}

function fromController(SelfEmailRequest $request): void
{
    // Comparison: controller-side narrowing via validated() works as designed.
    $_email = $request->validated('email');
    /** @psalm-check-type-exact $_email = string */

    $_age = $request->validated('age');
    /** @psalm-check-type-exact $_age = int|numeric-string */

    $_role = $request->validated('role');
    /** @psalm-check-type-exact $_role = 'admin'|'guest'|'user' */
}
?>
--EXPECTF--
