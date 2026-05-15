--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\NotIn;

/**
 * Issue #908: `new Enum(BackedEnum::class)` and `Rule::enum(BackedEnum::class)`
 * must narrow validated() output to the enum's backing-type literal union, not
 * leave it as `mixed`. `new In([...])` / `new NotIn([...])` must narrow on a
 * par with the `Rule::in(...)` fluent form already covered by
 * ValidationRuleInFluentNarrowingTest.
 *
 * Sibling tests live next to fluent narrowing so the two object-form entry
 * points stay symmetrical.
 */
enum IssueStatus: int
{
    case Active = 1;
    case Inactive = 0;
}

enum IssueRole: string
{
    case Admin = 'admin';
    case User = 'user';
}

enum IssueColour
{
    case Red;
    case Green;
    case Blue;
}

class EnumBackedIntRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', new Enum(IssueStatus::class)],
        ];
    }
}

function testEnumBackedInt(EnumBackedIntRequest $request): void
{
    // BackedEnum:int → literal-int union of case values
    $_status = $request->validated('status');
    /** @psalm-check-type-exact $_status = 0|1 */

    $_all = $request->validated();
    /** @psalm-check-type-exact $_all = array{status: 0|1} */
}

class EnumBackedStringRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'role' => ['required', new Enum(IssueRole::class)],
        ];
    }
}

function testEnumBackedString(EnumBackedStringRequest $request): void
{
    // BackedEnum:string → literal-string union of case backing values
    $_role = $request->validated('role');
    /** @psalm-check-type-exact $_role = 'admin'|'user' */
}

class EnumUnitRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'colour' => ['required', new Enum(IssueColour::class)],
        ];
    }
}

function testEnumUnit(EnumUnitRequest $request): void
{
    // Pure UnitEnum (no `tryFrom`): Laravel's Enum::passes() returns false for
    // every scalar input, so any narrowing would be wrong for the only path
    // through which a UnitEnum field could reach validated() (programmatic
    // `$request->merge(SomeUnit::Case)`, which produces an enum *instance*,
    // not a string). Stay at `mixed` until Laravel grows real UnitEnum
    // name-matching at runtime.
    $_colour = $request->validated('colour');
    /** @psalm-check-type-exact $_colour = mixed */
}

class FluentEnumBackedIntRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(IssueStatus::class)],
        ];
    }
}

function testFluentEnumBackedInt(FluentEnumBackedIntRequest $request): void
{
    // Rule::enum(...) routes through the same synthetic `enum:FQN` segment
    // as the `new Enum(...)` constructor form. Locked in so the fluent /
    // constructor entry points cannot diverge.
    $_status = $request->validated('status');
    /** @psalm-check-type-exact $_status = 0|1 */
}

class FluentEnumOnlySubsetRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // ->only([...]) further restricts the accepted cases at runtime,
            // but the analyzer's fluent unwrap collapses the chain to its
            // root, so we emit the full-case-set union. That's a sound
            // superset (no false positives, just less peak precision), and
            // a deliberate trade-off documented in tryExtractEnumRuleSegment.
            'status' => ['required', Rule::enum(IssueStatus::class)->only([IssueStatus::Active])],
        ];
    }
}

function testFluentEnumOnlySubsetUsesFullUnion(FluentEnumOnlySubsetRequest $request): void
{
    $_status = $request->validated('status');
    /** @psalm-check-type-exact $_status = 0|1 */
}

class EnumWithStringRuleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // 'first wins' for type-bearing rules — mirrors the
            // FluentInWithStringRuleRequest case in the In sibling test.
            // The 'string' segment resolves before the enum segment, so the
            // inferred type stays `string`.
            'role' => ['required', 'string', new Enum(IssueRole::class)],
        ];
    }
}

function testEnumWithStringRule(EnumWithStringRuleRequest $request): void
{
    $_role = $request->validated('role');
    /** @psalm-check-type-exact $_role = string */
}

class EnumWithVariableArgRequest extends FormRequest
{
    /**
     * @return array<string, list<string|\Illuminate\Validation\Rules\Enum>>
     */
    public function rules(): array
    {
        /** @var class-string<\UnitEnum> $enumClass */
        $enumClass = IssueStatus::class;

        return [
            'status' => ['required', new Enum($enumClass)],
        ];
    }
}

function testEnumWithVariableArgFallsBackToMixed(EnumWithVariableArgRequest $request): void
{
    // Variable arg: the analyzer cannot statically resolve the enum class, so
    // it falls back to the class: segment path. Type stays mixed; taint still
    // escapes via FIRST_PARTY_RULE_ESCAPES['…\\rules\\enum'].
    $_status = $request->validated('status');
    /** @psalm-check-type-exact $_status = mixed */
}

class EnumOptionalRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Missing 'required' → field is optional in validated() shape.
            'status' => [new Enum(IssueStatus::class)],
        ];
    }
}

function testEnumOptional(EnumOptionalRequest $request): void
{
    $_all = $request->validated();
    /** @psalm-check-type-exact $_all = array{status?: 0|1} */
}

class NewInArrayLiteralRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // `new In(...)` mirrors `Rule::in(...)` — both must narrow to the
            // same literal-string union for the same statically-resolvable
            // whitelist. Locked in here so the constructor form does not
            // silently regress to `mixed`.
            'workday' => ['required', new In(['half', 'full'])],
        ];
    }
}

function testNewInArrayLiteral(NewInArrayLiteralRequest $request): void
{
    $_workday = $request->validated('workday');
    /** @psalm-check-type-exact $_workday = 'full'|'half' */
}

class NewNotInArrayLiteralRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // not_in is a blocklist — no value-shape narrowing. Locked in here
            // so the constructor form behaves identically to the static-call
            // form (FluentNotInVariadicRequest) and to the `not_in:a,b`
            // string-rule form: the explicit `'string'` rule wins, type =
            // string.
            'role' => ['required', 'string', new NotIn(['banned', 'blocked'])],
        ];
    }
}

function testNewNotInArrayLiteral(NewNotInArrayLiteralRequest $request): void
{
    $_role = $request->validated('role');
    /** @psalm-check-type-exact $_role = string */
}

class EnumNullableRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // The 'nullable' modifier wraps every type-bearing rule's union
            // with `|null` via resolveRuleSegments(). Locking this in for the
            // enum path so a future refactor of the nullable handling can't
            // silently drop the null branch off enum narrowings.
            'status' => ['nullable', new Enum(IssueStatus::class)],
        ];
    }
}

function testEnumNullable(EnumNullableRequest $request): void
{
    $_status = $request->validated('status');
    /** @psalm-check-type-exact $_status = 0|1|null */
}

/**
 * Inline validate([...]) routes through the same extractRulePairsFromArrayNode
 * → tryExtractEnumRuleSegment / tryExtractInLikeRuleSegment path as the
 * FormRequest::rules() entry point. Locked in so the inline-validate call
 * shape stays in sync.
 */
function testInlineValidateWithEnumAndNewIn(\Illuminate\Http\Request $request): void
{
    $_data = $request->validate([
        'status' => ['required', new Enum(IssueStatus::class)],
        'role' => ['required', Rule::enum(IssueRole::class)],
        'workday' => ['required', new In(['half', 'full'])],
    ]);
    /** @psalm-check-type-exact $_data = array{role: 'admin'|'user', status: 0|1, workday: 'full'|'half'} */
}
?>
--EXPECTF--
