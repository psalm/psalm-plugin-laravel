--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * #1234 follow-up item 6: boolean() literal precision — an unconditional
 * `accepted` rule narrows to literal `true`, `declined` to literal `false`.
 * See ValidatedTypeHandler::resolveSelfBoolean().
 */
class BooleanLiteralRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'terms' => 'required|accepted',
            'newsletter' => 'required|declined',
            // Conditional — never authorizes literal precision.
            'promo' => 'accepted_if:plan,pro',
            // No `required` — presence not guaranteed.
            'optional_terms' => 'sometimes|accepted',
            // nullable → filter_var(null, FILTER_VALIDATE_BOOLEAN) = false,
            // outside the claimed literal `true`.
            'nullable_terms' => 'required|nullable|accepted',
            // Plain `boolean` rule — no literal to narrow to.
            'flag' => 'required|boolean',
            // Contradictory (both present) — the mutual-exclusion guard in
            // resolveSelfBoolean() must fall through to plain bool rather
            // than pick either literal.
            'contradictory' => 'required|accepted|declined',
        ];
    }

    public function booleanNarrowing(): void
    {
        $_terms = $this->boolean('terms');
        /** @psalm-check-type-exact $_terms = true */

        $_newsletter = $this->boolean('newsletter');
        /** @psalm-check-type-exact $_newsletter = false */

        $_promo = $this->boolean('promo');
        /** @psalm-check-type-exact $_promo = bool */

        $_optionalTerms = $this->boolean('optional_terms');
        /** @psalm-check-type-exact $_optionalTerms = bool */

        $_nullableTerms = $this->boolean('nullable_terms');
        /** @psalm-check-type-exact $_nullableTerms = bool */

        $_flag = $this->boolean('flag');
        /** @psalm-check-type-exact $_flag = bool */

        $_contradictory = $this->boolean('contradictory');
        /** @psalm-check-type-exact $_contradictory = bool */

        $_unknown = $this->boolean('does_not_exist');
        /** @psalm-check-type-exact $_unknown = bool */
    }

    public function dynamicKeyFallsThrough(string $key): bool
    {
        $_dynamic = $this->boolean($key);
        /** @psalm-check-type-exact $_dynamic = bool */

        return $_dynamic;
    }
}

function fromController(BooleanLiteralRequest $request): void
{
    // External call — dispatch keys off the receiver's static type, not $this.
    $_terms = $request->boolean('terms');
    /** @psalm-check-type-exact $_terms = true */

    $_newsletter = $request->boolean('newsletter');
    /** @psalm-check-type-exact $_newsletter = false */
}
?>
--EXPECTF--
