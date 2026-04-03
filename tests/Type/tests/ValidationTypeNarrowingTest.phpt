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

    // str()/string() always return Stringable regardless of rule — fall through to stub
    $_nameStr = $safe->str('name');
    /** @psalm-check-type-exact $_nameStr = \Illuminate\Support\Stringable */

    $_nameString = $safe->string('name');
    /** @psalm-check-type-exact $_nameString = \Illuminate\Support\Stringable */
}

class AcceptDeclineRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'terms' => 'accepted',
            'opt_out' => 'declined',
        ];
    }
}

function testAcceptedDeclinedTypes(AcceptDeclineRequest $request): void
{
    $_terms = $request->validated('terms');
    /** @psalm-check-type-exact $_terms = '1'|'on'|'true'|'yes'|1|true */

    $_optOut = $request->validated('opt_out');
    /** @psalm-check-type-exact $_optOut = '0'|'false'|'no'|'off'|0|false */
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

class NestedAddressRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'address.city' => 'required|string',
            'address.zip'  => 'required|string',
        ];
    }
}

function testNestedDotNotationShape(NestedAddressRequest $request): void
{
    $_all = $request->validated();
    /** @psalm-check-type-exact $_all = array{address: array{city: string, zip: string}} */
}

class MixedFlatAndNestedRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'           => 'required|string',
            'address.city'   => 'required|string',
            'address.street' => 'required|string',
        ];
    }
}

function testMixedFlatAndNestedShape(MixedFlatAndNestedRequest $request): void
{
    $_all = $request->validated();
    /** @psalm-check-type-exact $_all = array{address: array{city: string, street: string}, name: string} */
}

class DeepNestedRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'billing.address.city'   => 'required|string',
            'billing.address.street' => 'required|string',
        ];
    }
}

function testDeepNestingShape(DeepNestedRequest $request): void
{
    $_all = $request->validated();
    /** @psalm-check-type-exact $_all = array{billing: array{address: array{city: string, street: string}}} */
}

class OptionalNestedFieldRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'contact.email' => 'required|string',
            'contact.phone' => 'sometimes|string',
        ];
    }
}

function testOptionalNestedField(OptionalNestedFieldRequest $request): void
{
    $_all = $request->validated();
    /** @psalm-check-type-exact $_all = array{contact: array{email: string, phone?: string}} */
}

class NullableNestedFieldRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'user.name' => 'required|string',
            'user.bio'  => 'nullable|string',
        ];
    }
}

function testNullableNestedField(NullableNestedFieldRequest $request): void
{
    $_all = $request->validated();
    /** @psalm-check-type-exact $_all = array{user: array{bio?: null|string, name: string}} */
}

function testNestedDotNotationSingleField(NestedAddressRequest $request): void
{
    // validated('field') still works with dot-notation keys
    $_city = $request->validated('address.city');
    /** @psalm-check-type-exact $_city = string */
}

function testInlineValidateDotNotation(\Illuminate\Http\Request $request): void
{
    $_data = $request->validate([
        'user.name'  => 'required|string',
        'user.email' => 'required|email',
    ]);
    /** @psalm-check-type-exact $_data = array{user: array{email: string, name: string}} */
}
?>
--EXPECT--
