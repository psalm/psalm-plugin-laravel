--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class NamedArgumentUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string',
            'age' => 'required|integer',
        ];
    }

    public function inside(): void
    {
        // `$key` is the first parameter, not the first written argument
        $_input = $this->input(default: 'x', key: 'age');
        /** @psalm-check-type-exact $_input = int|non-empty-string */

        $_integer = $this->integer(default: 0, key: 'age');
        /** @psalm-check-type-exact $_integer = int */

        $_inputInOrder = $this->input(key: 'age');
        /** @psalm-check-type-exact $_inputInOrder = int|numeric-string */

        // No key: the default must not be read as the field name
        $_noKey = $this->input(default: 'age');
        /** @psalm-check-type-exact $_noKey = array<string, mixed> */
    }
}

function validated_with_reordered_named_arguments(NamedArgumentUserRequest $request): void
{
    $_withDefault = $request->validated(default: 0, key: 'age');
    /** @psalm-check-type-exact $_withDefault = 0|int|numeric-string */

    $_keyOnly = $request->validated(key: 'name');
    /** @psalm-check-type-exact $_keyOnly = string */
}

/** @param non-empty-list<string> $args */
function validated_with_spread(NamedArgumentUserRequest $request, array $args): void
{
    // The spread may supply the default, so the bare rule type is not provable
    $_spread = $request->validated('age', ...$args);
    /** @psalm-check-type-exact $_spread = mixed */
}
?>
--EXPECTF--
