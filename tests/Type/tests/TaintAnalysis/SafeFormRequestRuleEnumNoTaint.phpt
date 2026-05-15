--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Issue #908: `new Enum(BackedEnum::class)` and `Rule::enum(BackedEnum::class)`
 * must escape ALL_INPUT taint at sinks. The accepted set is bounded by the
 * developer-declared case backing values (source-code constants), so the trust
 * model matches `Rule::in([$whitelist])` which is already at ALL_INPUT.
 *
 * Sibling of {@see SafeFormRequestRuleInFluentNoTaint.phpt}.
 */
enum SafeRole: string
{
    case Admin = 'admin';
    case User = 'user';
}

final class EnumRequest extends FormRequest
{
    public function rules(): array
    {
        return ['role' => ['required', new Enum(SafeRole::class)]];
    }
}

function renderEnum(EnumRequest $request): void {
    echo $request->string('role');
}

final class FluentEnumRequest extends FormRequest
{
    public function rules(): array
    {
        return ['role' => ['required', Rule::enum(SafeRole::class)]];
    }
}

function renderFluentEnum(FluentEnumRequest $request): void {
    echo $request->string('role');
}
?>
--EXPECTF--
