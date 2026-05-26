--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Type narrowing for $this->input('field') calls inside a FormRequest subclass.
 *
 * The plugin already narrows $request->validated('field') / $request->safe()->input('field')
 * from controller code. This test pins the behavior when the FormRequest itself calls
 * $this->input(...), e.g. inside prepareForValidation(), passedValidation(), withValidator(),
 * or a custom helper method on the subclass.
 *
 * Status: not narrowed. ValidatedTypeHandler only routes input() narrowing through
 * the ValidatedInput<TRequest> generic — $this->input() on a FormRequest does not
 * extract the called class's rules. Until this is wired up, $this->input('email')
 * falls through to the InteractsWithInput stub's `mixed` return.
 */
class SelfEmailRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'age' => ['required', 'integer'],
            'role' => ['required', 'in:admin,user,guest'],
        ];
    }

    #[\Override]
    public function prepareForValidation(): void
    {
        // Current behavior: falls through to InteractsWithInput::input() stub return.
        $_email = $this->input('email');
        /** @psalm-check-type-exact $_email = mixed */

        $_age = $this->input('age');
        /** @psalm-check-type-exact $_age = mixed */

        $_role = $this->input('role');
        /** @psalm-check-type-exact $_role = mixed */
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
