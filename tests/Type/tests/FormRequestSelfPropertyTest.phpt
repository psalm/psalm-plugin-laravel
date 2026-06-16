--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests\PropertyNarrowing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Type narrowing for `$this->field` / `$request->field` magic reads on a
 * FormRequest (#1016) — property mirror of #1015's `input('field')`. Same gate
 * as `ValidatedTypeHandler::resolveSelfInput`: only presence-guaranteed fields
 * (required/present/accepted/declined, sans `sometimes`) narrow. Defers to a
 * real declaration or `@property`/`@property-read` PHPDoc first.
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

        // Not presence-guaranteed: no narrowing, so Psalm emits its usual
        // UndefinedThisPropertyFetch. Suppress it and assert the type stays mixed
        // (an undefined property has no inferred type to compare otherwise).
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
 * Real declared property wins: the rule's `string` must not override the
 * user's `public ?int $email`. The declared-field opt-out half of #1016.
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
 * Inherited declared property: a child whose rules() covers `email` defers to
 * the parent's `public ?int $email` — `hasDeclaredProperty()` reads merged
 * storage, not just the child's own declarations.
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
 * `@property` PHPDoc opts out. Deliberately `int|null` (not the rule's string)
 * so the assertion proves the @property branch fired.
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
 * `@property-read` also populates `pseudo_property_get_types` — same opt-out.
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
 * Inherited rules() — a child with no own rules() picks up the parent's set
 * (ValidationRuleAnalyzer walks `parent_class`).
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
