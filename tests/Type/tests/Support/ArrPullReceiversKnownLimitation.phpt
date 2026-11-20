--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Arr;

/**
 * Known limitation of the Arr::pull() by-ref rewrite (ArrGetHandler::afterMethodCallAnalysis()): it
 * only handles a plain variable. Psalm writes the parameter's `array` back into a property or array
 * item receiver itself, so the shape is lost there and a typed property is reported as a coercion
 * from `array<array-key, mixed>`. Rewriting those would mean narrowing a declared property type
 * and the owning array's shape, which is not modelled.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/2345
 */
final class PullHolder
{
    /** @var array{a: int, b: string} */
    public array $data = ['a' => 1, 'b' => 'x'];

    public function pullFromProperty(): void
    {
        $_value = Arr::pull($this->data, 'a');
    }
}

/** @param array{rows: array{a: int, b: string}} $data */
function test_pull_from_array_item(array $data): void
{
    $_value = Arr::pull($data['rows'], 'a');
    /** @psalm-check-type-exact $data['rows'] = array<array-key, mixed> */
}
?>
--EXPECTF--
MixedPropertyTypeCoercion on line %d: $this->data expects 'array{a: int, b: string}',  parent type `array<array-key, mixed>` provided
