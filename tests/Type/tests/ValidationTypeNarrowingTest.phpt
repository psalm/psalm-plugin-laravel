--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string',
            'age' => 'required|integer',
            'score' => 'required|numeric',
            'role' => 'in:admin,user,guest',
            'bio' => 'nullable|string',
            'nickname' => 'sometimes|string',
        ];
    }
}

function testValidatedSingleField(StoreUserRequest $request): void
{
    // string rule → string
    $_name = $request->validated('name');
    /** @psalm-check-type-exact $_name = string */

    // integer rule → int|numeric-string
    $_age = $request->validated('age');
    /** @psalm-check-type-exact $_age = int|numeric-string */

    // numeric rule → float|int|numeric-string
    $_score = $request->validated('score');
    /** @psalm-check-type-exact $_score = float|int|numeric-string */

    // in rule → literal union
    $_role = $request->validated('role');
    /** @psalm-check-type-exact $_role = 'admin'|'guest'|'user' */

    // nullable string → null|string
    $_bio = $request->validated('bio');
    /** @psalm-check-type-exact $_bio = null|string */
}

function testValidatedWithDefault(StoreUserRequest $request): void
{
    // validated('field', default) → union of rule type and default type
    $_nameWithDefault = $request->validated('name', 'anonymous');
    /** @psalm-check-type-exact $_nameWithDefault = string */

    $_ageWithDefault = $request->validated('age', 0);
    /** @psalm-check-type-exact $_ageWithDefault = 0|int|numeric-string */
}

function testValidatedFullShape(StoreUserRequest $request): void
{
    $_all = $request->validated();
    /** @psalm-check-type-exact $_all = array{age: int|numeric-string, bio?: null|string, name: string, nickname?: string, role?: 'admin'|'guest'|'user', score: float|int|numeric-string} */
}

class WildcardRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'tags' => 'required|array',
            'tags.*' => 'string',
            'items' => 'required|array',
            'items.*.id' => 'required|integer',
            'items.*.name' => 'required|string',
        ];
    }
}

function testWildcardRules(WildcardRequest $request): void
{
    $_all = $request->validated();
    /** @psalm-check-type-exact $_all = array{tags: list<string>, items: list<array{id: int|numeric-string, name: string}>} */
}

function testSafeReturnsValidatedInput(StoreUserRequest $request): void
{
    $_safe = $request->safe();
    /** @psalm-check-type-exact $_safe = \Illuminate\Support\ValidatedInput<StoreUserRequest&static> */
}

function testSafeWithKeys(StoreUserRequest $request): void
{
    // safe(['key1', 'key2']) → partial array shape with only those fields
    $_partial = $request->safe(['name', 'age']);
    /** @psalm-check-type-exact $_partial = array{name: string, age: int|numeric-string} */
}

function testSafeInputNarrowsType(StoreUserRequest $request): void
{
    // safe() returns ValidatedInput<StoreUserRequest>, so input('field') is narrowed
    $safe = $request->safe();
    $_name = $safe->input('name');
    /** @psalm-check-type-exact $_name = string */

    $_age = $safe->input('age');
    /** @psalm-check-type-exact $_age = int|numeric-string */

    // str() and string() also narrow via the TRequest template
    $_nameStr = $safe->str('name');
    /** @psalm-check-type-exact $_nameStr = string */

    $_nameString = $safe->string('name');
    /** @psalm-check-type-exact $_nameString = string */
}

function testInlineValidate(\Illuminate\Http\Request $request): void
{
    // $request->validate([...]) → array shape from inline rules
    $_data = $request->validate([
        'count' => 'required|integer',
        'label' => 'required|string',
    ]);
    /** @psalm-check-type-exact $_data = array{count: int|numeric-string, label: string} */
}

function testInlineValidateArrayFormat(\Illuminate\Http\Request $request): void
{
    // Array format rules
    $_data = $request->validate([
        'id' => ['required', 'uuid'],
        'active' => ['boolean'],
    ]);
    /** @psalm-check-type-exact $_data = array{active?: '0'|'1'|0|1|bool, id: string} */
}
?>
--EXPECT--
