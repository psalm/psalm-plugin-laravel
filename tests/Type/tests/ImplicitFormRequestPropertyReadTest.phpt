--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-with-optin-custom-issues.xml
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests\ImplicitRead;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The opt-in ImplicitFormRequestPropertyRead rule flags an undeclared magic property read on a
 * FormRequest subclass ($this->field / $request->field) and asks for an explicit
 * validated()/safe()/input() accessor instead. Only registered under the opt-in config used by
 * this test (see --ARGS-- above).
 *
 * It fires on exactly the fetches the plugin silently narrows (#1022) — a presence-guaranteed,
 * undeclared field — and defers everywhere the plugin never narrowed (declared members, and
 * no-rule / non-guaranteeing fields that Psalm already reports as UndefinedThisPropertyFetch).
 * The narrowing itself is unaffected: each flagged read still resolves to its rule type.
 *
 * Coverage: flagged in-class `$this->field`, external `$request->field`, a `?? ...` read, and a
 * multi-level (FormRequest <- abstract Base <- concrete Child) inherited rule; deferred on declared
 * / `@property` members, the `safe()`/`validated()`/`input()` accessors, a non-guaranteeing rule,
 * an assignment target, and the `isset()`/`unset()`/`empty()` presence-test contexts.
 */
class StoreUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'age' => ['required', 'integer'],
            // Not presence-guaranteed: the plugin never narrows it, so it is not flagged.
            'nickname' => ['sometimes', 'string'],
        ];
    }

    public function store(): void
    {
        // Flagged: presence-guaranteed magic reads the plugin silently narrows. The narrowing
        // still applies (the rule emits an issue but does not change the inferred type).
        $_email = $this->email;
        /** @psalm-check-type-exact $_email = string */

        $_age = $this->age;
        /** @psalm-check-type-exact $_age = int|numeric-string */

        // Not flagged: explicit, contract-respecting accessors. `safe()->email` is a property read
        // off a ValidatedInput, not a FormRequest, so the rule skips it (the structural guard for
        // the suggested fix). `validated()` / `input()` are method calls the rule never inspects.
        // The cast on `safe()->email` is real (ValidatedInput::__get is mixed); `validated()` and
        // `input()` already narrow to the rule type (#1015), so they need no cast.
        $_safe = (string) $this->safe()->email;
        /** @psalm-check-type-exact $_safe = string */

        $_validated = $this->validated('email');
        /** @psalm-check-type-exact $_validated = string */

        $_input = $this->input('email');
        /** @psalm-check-type-exact $_input = string */

        // Not flagged: no presence-guaranteeing rule, so the plugin never narrowed it — Psalm's own
        // UndefinedThisPropertyFetch already covers the read, and the rule defers to avoid
        // double-reporting.
        /** @psalm-suppress UndefinedThisPropertyFetch */
        $_nickname = $this->nickname;
        /** @psalm-check-type-exact $_nickname = mixed */
    }

    public function writeIsNotARead(): void
    {
        // A write is UndefinedThisPropertyAssignment (the plugin's Request stub omits __set), never
        // a magic input read — the rule must not fire on the assignment target (it only inspects
        // read PropertyFetch nodes; AssignmentAnalyzer routes the LHS elsewhere).
        /** @psalm-suppress UndefinedThisPropertyAssignment */
        $this->email = 'literal';
    }

    public function presenceTestContexts(): void
    {
        // Deferred: isset()/unset() invoke __isset (or removal), not __get, so they are not magic
        // input reads. empty() shares the same inside_isset context and is deferred too (a minor,
        // deliberate false negative). None of these emit ImplicitFormRequestPropertyRead.
        $_isset = isset($this->email);
        /** @psalm-check-type-exact $_isset = bool */

        unset($this->email);

        $_empty = empty($this->email);
        /** @psalm-check-type-exact $_empty = bool */

        // Flagged: `?? ...` genuinely reads through __get and is not in the isset context, so it
        // stays a magic read. The collateral RedundantCondition/TypeDoesNotContainType come from
        // the field being narrowed to a non-null string (the `?? 'fallback'` is dead), independent
        // of this rule.
        /**
         * @psalm-suppress RedundantCondition
         * @psalm-suppress TypeDoesNotContainType
         */
        $_coalesce = $this->email ?? 'fallback';
        /** @psalm-check-type-exact $_coalesce = string */
    }
}

/**
 * A declared member opts out entirely: real property or @property PHPDoc means the field is not
 * magic, so the rule never fires (it reuses resolveRuleForProperty(), which defers on declared
 * members).
 *
 * @property int|null $declaredViaPhpDoc
 */
class DeclaredFieldRequest extends FormRequest
{
    public ?int $realProperty = null;

    public function rules(): array
    {
        return [
            'realProperty' => ['required', 'integer'],
            'declaredViaPhpDoc' => ['required', 'integer'],
        ];
    }

    public function check(): void
    {
        $_real = $this->realProperty;
        /** @psalm-check-type-exact $_real = int|null */

        $_declared = $this->declaredViaPhpDoc;
        /** @psalm-check-type-exact $_declared = int|null */
    }
}

/**
 * Multi-level subclass: FormRequest <- abstract Base <- concrete Child. The abstract base is
 * skipped at registration; the concrete child is registered, and resolveRuleForProperty() walks
 * the full parent_classes chain to find the inherited rule. A read on the concrete child is flagged.
 */
abstract class BaseFormRequest extends FormRequest
{
    public function rules(): array
    {
        return ['email' => ['required', 'email']];
    }
}

class ConcreteChildRequest extends BaseFormRequest
{
    public function readInheritedRule(): void
    {
        $_email = $this->email;
        /** @psalm-check-type-exact $_email = string */
    }
}

/**
 * External `$request->field` access from a controller flags the same as the in-class `$this->field`
 * access — same rule lookup, same gate.
 */
function fromController(StoreUserRequest $request): void
{
    $_email = $request->email;
    /** @psalm-check-type-exact $_email = string */
}
?>
--EXPECTF--
ImplicitFormRequestPropertyRead on line %d: StoreUserRequest::$email is a magic read off the request input bag through Laravel's Request::__get. Use validated('email'), safe()->email, or input('email') instead.
ImplicitFormRequestPropertyRead on line %d: StoreUserRequest::$age is a magic read off the request input bag through Laravel's Request::__get. Use validated('age'), safe()->age, or input('age') instead.
ImplicitFormRequestPropertyRead on line %d: StoreUserRequest::$email is a magic read off the request input bag through Laravel's Request::__get. Use validated('email'), safe()->email, or input('email') instead.
ImplicitFormRequestPropertyRead on line %d: ConcreteChildRequest::$email is a magic read off the request input bag through Laravel's Request::__get. Use validated('email'), safe()->email, or input('email') instead.
ImplicitFormRequestPropertyRead on line %d: StoreUserRequest::$email is a magic read off the request input bag through Laravel's Request::__get. Use validated('email'), safe()->email, or input('email') instead.
