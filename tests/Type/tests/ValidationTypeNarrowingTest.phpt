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

function testValidatedFullShape(StoreUserRequest $request): void
{
    $_all = $request->validated();
    /** @psalm-check-type-exact $_all = array{name: string, age: int|numeric-string, score: float|int|numeric-string, role: 'admin'|'guest'|'user', bio: null|string, nickname?: string} */
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
    /** @psalm-check-type-exact $_safe = \Illuminate\Support\ValidatedInput|array<string, mixed> */
}
?>
--EXPECT--
