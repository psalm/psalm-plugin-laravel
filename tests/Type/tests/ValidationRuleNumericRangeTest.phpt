--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Issue #1234: numeric range narrowing (min/max/between/size/gt/gte/lt/lte)
 * on integer()/float()/boolean() accessors — see
 * ValidationRuleAnalyzer::applyNumericRangeNarrowing() and
 * ValidatedTypeHandler::resolveSelfInteger().
 *
 * #1234 follow-up item 1: the same gate stack on safe()->integer() — see
 * ValidatedTypeHandler::resolveValidatedInputInteger().
 */
class NumericRangeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'portions' => ['bail', 'required', 'integer', 'min:1'],
            'age_range' => 'required|integer|between:0,24',
            'negative_offset' => 'required|integer|min:-5',
            'price' => 'required|numeric|min:0',
            'confirmed' => 'required|boolean',
            'label' => 'required|string',
            'quota' => 'required|integer|size:5',
            'stock' => 'required|integer|gt:0',
            'quantity' => 'integer|min:1', // no required — may be absent
            'age' => 'required|nullable|integer|min:18', // nullable value
            'terms' => 'accepted', // no explicit integer rule
            'discount' => 'required|numeric|gt:0', // no explicit integer rule
        ];
    }

    /**
     * @return positive-int
     */
    public function getPortions(): int
    {
        // int<1, max> IS positive-int internally — no MoreSpecificReturnType.
        return $this->integer('portions');
    }

    public function selfAccessorNarrowing(): void
    {
        $_portions = $this->integer('portions');
        /** @psalm-check-type-exact $_portions = int<1, max> */

        $_ageRange = $this->integer('age_range');
        /** @psalm-check-type-exact $_ageRange = int<0, 24> */

        $_negativeOffset = $this->integer('negative_offset');
        /** @psalm-check-type-exact $_negativeOffset = int<-5, max> */

        $_quota = $this->integer('quota');
        /** @psalm-check-type-exact $_quota = 5 */

        // No explicit `integer` rule (#1237) — (int) can project a float
        // outside the rule-derived range (e.g. (int) "0.5" = 0), so a bare
        // `numeric` rule no longer authorizes integer() narrowing.
        $_price = $this->integer('price');
        /** @psalm-check-type-exact $_price = int */

        // No float-range type in Psalm — float() always plain float.
        $_priceFloat = $this->float('price');
        /** @psalm-check-type-exact $_priceFloat = float */

        // No narrowing target — boolean() always plain bool.
        $_confirmed = $this->boolean('confirmed');
        /** @psalm-check-type-exact $_confirmed = bool */

        // No int component (string rule) — falls through to plain int.
        $_label = $this->integer('label');
        /** @psalm-check-type-exact $_label = int */

        $_unknown = $this->integer('does_not_exist');
        /** @psalm-check-type-exact $_unknown = int */

        // gt:0 exclusive bound → lower = 1 (±1 math unit-tested separately).
        $_stock = $this->integer('stock');
        /** @psalm-check-type-exact $_stock = int<1, max> */

        // No required — absent field would cast default 0, outside int<1, max>.
        $_quantity = $this->integer('quantity');
        /** @psalm-check-type-exact $_quantity = int */

        // nullable → (int) null = 0, outside int<18, max>.
        $_age = $this->integer('age');
        /** @psalm-check-type-exact $_age = int */

        // accepted alone: TLiteralInt(1) exists in the rule type, but
        // 'yes'/'on' also pass and (int) "yes" = 0 — no explicit integer rule.
        $_terms = $this->integer('terms');
        /** @psalm-check-type-exact $_terms = int */

        // numeric|gt:0: infers int<1, max> at the type level, but 0.5 passes
        // and (int) "0.5" = 0 — no explicit integer rule.
        $_discount = $this->integer('discount');
        /** @psalm-check-type-exact $_discount = int */
    }

    public function dynamicKeyFallsThrough(string $key): int
    {
        // Non-literal key — falls through to plain int.
        $_dynamic = $this->integer($key);
        /** @psalm-check-type-exact $_dynamic = int */

        return $_dynamic;
    }
}

function fromController(NumericRangeRequest $request): void
{
    // External call — dispatch keys off the receiver's static type, not $this.
    $_portions = $request->integer('portions');
    /** @psalm-check-type-exact $_portions = int<1, max> */

    // validated() narrows via the range accumulator directly.
    $_portionsValidated = $request->validated('portions');
    /** @psalm-check-type-exact $_portionsValidated = int<1, max>|numeric-string */

    $_ageRange = $request->integer('age_range');
    /** @psalm-check-type-exact $_ageRange = int<0, 24> */

    // safe()->integer() (#1234 follow-up item 1) — same gate stack as the
    // live Request's integer(), applied to ValidatedInput's rules lookup.
    $_portionsSafe = $request->safe()->integer('portions');
    /** @psalm-check-type-exact $_portionsSafe = int<1, max> */

    // Optional field (no `required`) — ValidatedInput::integer() would fall
    // back to its own $default (0) if the field is absent from safe() output.
    $_quantitySafe = $request->safe()->integer('quantity');
    /** @psalm-check-type-exact $_quantitySafe = int */

    // Nullable field — (int) null = 0, outside int<18, max>.
    $_ageSafe = $request->safe()->integer('age');
    /** @psalm-check-type-exact $_ageSafe = int */

    // numeric|gt:0, no explicit `integer` rule — (int) "0.5" = 0 would
    // escape the inferred int<1, max>.
    $_discountSafe = $request->safe()->integer('discount');
    /** @psalm-check-type-exact $_discountSafe = int */
}

/**
 * Issue #1237: exclude/exclude_if/exclude_unless/exclude_with/exclude_without
 * defeat the presence guarantee — Laravel's Validator skips a field's other
 * rules entirely once an unconditional exclude fires, so 'required' alongside
 * it guarantees nothing. See ResolvedRule::guaranteesPresence().
 */
class ExcludedFieldRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'legacy_id' => 'exclude|required|integer|min:1',
            'name' => 'required|string',
        ];
    }

    public function excludedFieldNarrowing(): void
    {
        // Falls through to plain int, not int<1, max>.
        $_legacyId = $this->integer('legacy_id');
        /** @psalm-check-type-exact $_legacyId = int */

        // Same gate applies to input() — falls through to mixed.
        $_legacyIdInput = $this->input('legacy_id');
        /** @psalm-check-type-exact $_legacyIdInput = mixed */
    }
}

function fromControllerExcluded(ExcludedFieldRequest $request): void
{
    // Full-shape validated(): 'legacy_id' is now possibly_undefined — Laravel's
    // exclude mechanism means it may never reach validated() output, 'required'
    // notwithstanding.
    $_all = $request->validated();
    /** @psalm-check-type-exact $_all = array{legacy_id?: int<1, max>|numeric-string, name: string} */

    // Excluded field — same gate applies to safe()->integer() (#1234 follow-up item 1).
    $_legacyIdSafe = $request->safe()->integer('legacy_id');
    /** @psalm-check-type-exact $_legacyIdSafe = int */
}
?>
--EXPECTF--
