--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests\PropertyNarrowing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Type narrowing for `$this->field` / `$request->field` magic property reads
 * on a FormRequest subclass — the property access mirror of #1015's
 * `$this->input('field')` handling.
 *
 * `Request::__get($key)` reads from the input bag at runtime, so the same
 * rule-driven narrowing applies. Soundness gate identical to
 * `ValidatedTypeHandler::resolveSelfInput`: only presence-guaranteed fields
 * (required / present / accepted / declined, sans `sometimes`) narrow.
 *
 * Resolution priority (the first match wins, the rest are deferred to):
 *   1. real declared property on the subclass (`public string $email`)
 *   2. `@property` / `@property-read` PHPDoc on the subclass
 *      (inherited from a parent counts the same — Psalm merges
 *      `pseudo_property_get_types` during population)
 *   3. rule with unconditional presence guarantee
 *
 * See issue #1016.
 */
class SelfEmailPropertyRequest extends FormRequest
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
        ];
    }

    #[\Override]
    public function prepareForValidation(): void
    {
        // Required + type rule — narrows.
        $_email = $this->email;
        /** @psalm-check-type-exact $_email = string */

        $_age = $this->age;
        /** @psalm-check-type-exact $_age = int|numeric-string */

        $_role = $this->role;
        /** @psalm-check-type-exact $_role = 'admin'|'guest'|'user' */

        // present / accepted / declined — also narrow.
        $_comment = $this->comment;
        /** @psalm-check-type-exact $_comment = string */

        $_terms = $this->terms;
        /** @psalm-check-type-exact $_terms = '1'|'on'|'true'|'yes'|1|true */

        $_no_marketing = $this->no_marketing;
        /** @psalm-check-type-exact $_no_marketing = '0'|'false'|'no'|'off'|0|false */

        // sometimes alone — not presence-guaranteed: defer to default analysis.
        // Without a presence guarantee, the handler returns null from every
        // provider, so Psalm falls back to its usual UndefinedThisPropertyFetch.
        // We can't assert "no narrowing" via @psalm-check-type-exact alone
        // (an undefined property has no inferred type to compare against), so
        // we suppress the expected issue and assert the post-suppression type
        // remains mixed.
        /** @psalm-suppress UndefinedThisPropertyFetch */
        $_nickname = $this->nickname;
        /** @psalm-check-type-exact $_nickname = mixed */

        /** @psalm-suppress UndefinedThisPropertyFetch */
        $_biography = $this->biography;
        /** @psalm-check-type-exact $_biography = mixed */

        /** @psalm-suppress UndefinedThisPropertyFetch */
        $_optional_email = $this->optional_email;
        /** @psalm-check-type-exact $_optional_email = mixed */

        /** @psalm-suppress UndefinedThisPropertyFetch */
        $_spouse_name = $this->spouse_name;
        /** @psalm-check-type-exact $_spouse_name = mixed */
    }
}

/**
 * Real declared property wins: the rule's `string` type must NOT override
 * the user's explicit `public ?int $email` declaration. This is the
 * "ensure it doesn't create issues for declared fields" half of the issue —
 * a FormRequest that opts a field out of magic-read narrowing keeps the
 * declared type intact.
 */
class DeclaredPropertyRequest extends FormRequest
{
    public ?int $email = null;

    public function rules(): array
    {
        return ['email' => ['required', 'email']];
    }

    public function check(): void
    {
        $_email = $this->email;
        /** @psalm-check-type-exact $_email = int|null */
    }
}

/**
 * Inherited declared property: a child whose rules() covers `email` must
 * still defer to the parent's `public ?int $email` declaration. Locks in
 * that `hasDeclaredProperty()` consults the merged storage (parent +
 * child) rather than the child's own declared set.
 */
class ParentWithDeclaredProperty extends FormRequest
{
    public ?int $email = null;

    public function rules(): array
    {
        return [];
    }
}

class ChildInheritsDeclaredProperty extends ParentWithDeclaredProperty
{
    #[\Override]
    public function rules(): array
    {
        return ['email' => ['required', 'email']];
    }

    public function check(): void
    {
        $_email = $this->email;
        /** @psalm-check-type-exact $_email = int|null */
    }
}

/**
 * `@property` PHPDoc wins: same opt-out semantics as a real declaration.
 * Uses a deliberately-different type (int|null) so the assertion proves
 * the @property branch fired rather than coincidentally matching the
 * rule's string type.
 *
 * @property int|null $email
 */
class AtPropertyRequest extends FormRequest
{
    public function rules(): array
    {
        return ['email' => ['required', 'email']];
    }

    public function check(): void
    {
        $_email = $this->email;
        /** @psalm-check-type-exact $_email = int|null */
    }
}

/**
 * `@property-read` is the read-only PHPDoc variant — also populates
 * `pseudo_property_get_types`, so the same opt-out applies.
 *
 * @property-read int|null $email
 */
class AtPropertyReadRequest extends FormRequest
{
    public function rules(): array
    {
        return ['email' => ['required', 'email']];
    }

    public function check(): void
    {
        $_email = $this->email;
        /** @psalm-check-type-exact $_email = int|null */
    }
}

/**
 * Inherited rules() — a child subclass with no own rules() picks up the
 * parent's rule set via `ValidationRuleAnalyzer::extractRulesFromClass()`,
 * which walks `parent_class`. Pairs with the existing `ChildEmailRequest`
 * coverage in FormRequestSelfInputTest.phpt.
 */
class ChildPropertyRequest extends SelfEmailPropertyRequest
{
    public function customChildAccessor(): string
    {
        $_email = $this->email;
        /** @psalm-check-type-exact $_email = string */

        return $_email;
    }
}

function fromController(SelfEmailPropertyRequest $request): void
{
    // External access: `$req->field` from controller code narrows the same
    // way as the in-FormRequest `$this->field` access. Same rule lookup,
    // same gate.
    $_email = $request->email;
    /** @psalm-check-type-exact $_email = string */

    $_age = $request->age;
    /** @psalm-check-type-exact $_age = int|numeric-string */
}
?>
--EXPECTF--
