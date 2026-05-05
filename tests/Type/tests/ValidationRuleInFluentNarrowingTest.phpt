--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Issue #873: Rule::in(...) fluent builder must narrow to the same
 * literal-string union as the equivalent 'in:a,b,c' string rule.
 *
 * Forms covered: variadic strings, single string, array literal.
 * Statically-unresolvable args fall back to mixed.
 */
class FluentInArrayLiteralRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'workday' => ['required', Rule::in(['half', 'full'])],
        ];
    }
}

function testFluentInArrayLiteral(FluentInArrayLiteralRequest $request): void
{
    $_workday = $request->validated('workday');
    /** @psalm-check-type-exact $_workday = 'full'|'half' */

    $_all = $request->validated();
    /** @psalm-check-type-exact $_all = array{workday: 'full'|'half'} */
}

class FluentInVariadicRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'workday' => ['required', Rule::in('half', 'full')],
        ];
    }
}

function testFluentInVariadic(FluentInVariadicRequest $request): void
{
    $_workday = $request->validated('workday');
    /** @psalm-check-type-exact $_workday = 'full'|'half' */
}

class FluentInSingleStringRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in('admin')],
        ];
    }
}

function testFluentInSingleString(FluentInSingleStringRequest $request): void
{
    $_role = $request->validated('role');
    /** @psalm-check-type-exact $_role = 'admin' */
}

/**
 * Mixing 'string' before Rule::in(...) is a documented "first wins" behaviour:
 * the 'string' segment is type-bearing and resolves first, so the inferred
 * type is `string`, not the literal union. This mirrors what the equivalent
 * `'required|string|in:half,full'` string-form rule produces and is locked in
 * here so the fluent form does not unintentionally diverge.
 */
class FluentInWithStringRuleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'workday' => ['required', 'string', Rule::in(['half', 'full'])],
        ];
    }
}

function testFluentInWithStringRule(FluentInWithStringRuleRequest $request): void
{
    $_workday = $request->validated('workday');
    /** @psalm-check-type-exact $_workday = string */
}

class FluentInWithVariableRequest extends FormRequest
{
    /**
     * @return array<string, list<string|\Illuminate\Validation\Rules\In>>
     */
    public function rules(): array
    {
        $allowed = ['half', 'full'];

        return [
            'workday' => ['required', Rule::in($allowed)],
        ];
    }
}

function testFluentInWithVariableArgsFallsBackToMixed(FluentInWithVariableRequest $request): void
{
    // Variable arg: the analyzer cannot statically resolve the whitelist, so
    // it falls back to the class: segment path. Type stays mixed; taint still
    // escapes via FIRST_PARTY_RULE_ESCAPES['…\\rules\\in'].
    $_workday = $request->validated('workday');
    /** @psalm-check-type-exact $_workday = mixed */
}

enum SortOrder: string
{
    case Asc = 'asc';
    case Desc = 'desc';
}

class FluentInWithEnumCasesRequest extends FormRequest
{
    /**
     * @return array<string, list<string|\Illuminate\Validation\Rules\In>>
     */
    public function rules(): array
    {
        return [
            'order' => ['required', Rule::in(SortOrder::cases())],
        ];
    }
}

function testFluentInWithEnumCasesFallsBackToMixed(FluentInWithEnumCasesRequest $request): void
{
    // SortOrder::cases() is a method call, not an array literal, so the
    // analyzer bails to the class: path and the type stays mixed.
    $_order = $request->validated('order');
    /** @psalm-check-type-exact $_order = mixed */
}

class FluentInWithCommaInValueRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // 'a,b' as a single whitelisted value is the canonical reason to
            // prefer Rule::in over the 'in:a,b' string form. Emitting
            // 'in:a,b' for this would split into 'a'|'b' (unsound), so the
            // analyzer must bail to the class: path → mixed.
            'pair' => ['required', Rule::in(['a,b', 'c'])],
        ];
    }
}

function testFluentInWithCommaInValueFallsBackToMixed(FluentInWithCommaInValueRequest $request): void
{
    $_pair = $request->validated('pair');
    /** @psalm-check-type-exact $_pair = mixed */
}

class FluentInWithLeadingWhitespaceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // inRuleToLiteralUnion() trims each comma-separated segment, so
            // emitting 'in: a' would silently narrow to 'a'. Laravel preserves
            // whitespace at runtime (its __toString quotes each value and
            // parseParameters uses str_getcsv), so trimming would exclude a
            // value the runtime accepts. Bail to mixed.
            'token' => ['required', Rule::in([' a', 'b'])],
        ];
    }
}

function testFluentInWithLeadingWhitespaceFallsBackToMixed(FluentInWithLeadingWhitespaceRequest $request): void
{
    $_token = $request->validated('token');
    /** @psalm-check-type-exact $_token = mixed */
}

class FluentInWithTrailingWhitespaceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Variadic-form sibling of the leading-whitespace case.
            'token' => ['required', Rule::in('a ', 'b')],
        ];
    }
}

function testFluentInWithTrailingWhitespaceFallsBackToMixed(FluentInWithTrailingWhitespaceRequest $request): void
{
    $_token = $request->validated('token');
    /** @psalm-check-type-exact $_token = mixed */
}

class FluentInWithEmptyStringRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // 'in:' is parsed as a parameter-less rule, so inRuleToLiteralUnion
            // would return the unconstrained `string` type rather than the
            // singleton ''. Bail to mixed.
            'opt' => ['required', Rule::in(['', 'something'])],
        ];
    }
}

function testFluentInWithEmptyStringFallsBackToMixed(FluentInWithEmptyStringRequest $request): void
{
    $_opt = $request->validated('opt');
    /** @psalm-check-type-exact $_opt = mixed */
}

class FluentNotInVariadicRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::notIn('banned', 'blocked')],
        ];
    }
}

function testFluentNotInVariadic(FluentNotInVariadicRequest $request): void
{
    // not_in does not narrow a type (no value-shape guarantee from a
    // blocklist), so the 'string' rule still wins. Locked in here so the
    // notIn branch of tryExtractInLikeRuleSegment behaves identically to
    // the previous class: path.
    $_role = $request->validated('role');
    /** @psalm-check-type-exact $_role = string */
}

/**
 * Optional field (no required) keeps possibly-undefined in the validated()
 * shape, parallel to the string-rule case in ValidationTypeNarrowingTest.
 */
class FluentInOptionalRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'workday' => [Rule::in(['half', 'full'])],
        ];
    }
}

function testFluentInOptional(FluentInOptionalRequest $request): void
{
    $_all = $request->validated();
    /** @psalm-check-type-exact $_all = array{workday?: 'full'|'half'} */
}

/**
 * Inline validate([...]) with Rule::in(...) routes through the same
 * extractRulePairsFromArrayNode → tryExtractInLikeRuleSegment path as the
 * FormRequest::rules() entry point. Locked in here so the inline-validate
 * call shape stays in sync with the FormRequest case.
 */
function testInlineValidateWithFluentIn(\Illuminate\Http\Request $request): void
{
    $_data = $request->validate([
        'workday' => ['required', Rule::in(['half', 'full'])],
        'role' => ['required', Rule::in('admin', 'user')],
    ]);
    /** @psalm-check-type-exact $_data = array{role: 'admin'|'user', workday: 'full'|'half'} */
}
?>
--EXPECTF--
