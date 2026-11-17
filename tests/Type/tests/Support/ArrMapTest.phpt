--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Arr;

/**
 * Arr::map() keeps the input keys and replaces the values with the callback result;
 * Arr::mapWithKeys() takes both key and value from the single pair the callback returns.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/2337
 */

/** @param array<string, int> $array */
function test_map_keeps_string_keys(array $array): void
{
    $_result = Arr::map($array, static fn (int $value, string $key): string => $key . $value);
    /** @psalm-check-type-exact $_result = array<string, non-falsy-string> */
}

/** @param list<int> $list */
function test_map_list_keeps_int_keys(array $list): void
{
    $_result = Arr::map($list, static fn (int $value, int $key): float => $value * $key / 2);
    /** @psalm-check-type-exact $_result = array<int<0, max>, float> */
}

/** @param array<string, int> $array */
function test_map_one_param_callback(array $array): void
{
    $_result = Arr::map($array, static fn (int $value): bool => $value > 0);
    /** @psalm-check-type-exact $_result = array<string, bool> */
}

function test_map_empty_array_is_not_non_empty(): void
{
    $_result = Arr::map([], static fn (): int => 1);
    /** @psalm-check-type-exact $_result = array<never, 1> */
}

/** @param array<string, int> $array */
function test_mapWithKeys_rekeys(array $array): void
{
    $_result = Arr::mapWithKeys($array, static fn (int $value, string $key): array => [$value => $key]);
    /** @psalm-check-type-exact $_result = array<int, string> */
}

/** @param list<string> $list */
function test_mapWithKeys_one_param_callback(array $list): void
{
    $_result = Arr::mapWithKeys($list, static fn (string $value): array => [$value => strlen($value)]);
    /** @psalm-check-type-exact $_result = array<string, int<0, max>> */
}

/** @param list<string> $list */
function test_map_first_class_callable(array $list): void
{
    $_result = Arr::map($list, strtoupper(...));
    /** @psalm-check-type-exact $_result = array<int<0, max>, string> */
}

/** @param array<string, string> $array */
function test_map_static_method_first_class_callable(array $array): void
{
    $_result = Arr::map($array, ArrMapFixture::shout(...));
    /** @psalm-check-type-exact $_result = array<string, string> */
}

/** @param list<string> $list */
function test_mapWithKeys_first_class_callable(array $list): void
{
    $_result = Arr::mapWithKeys($list, ArrMapFixture::pair(...));
    /** @psalm-check-type-exact $_result = array<string, string> */
}

function test_mapWithKeys_empty_array_is_not_non_empty(): void
{
    $_result = Arr::mapWithKeys([], static fn (): array => ['a' => 1]);
    /** @psalm-check-type-exact $_result = array<'a', 1> */
}

/** @param array<string, int> $array */
function test_map_callback_param_mismatch_is_reported(array $array): void
{
    Arr::map($array, static fn (string $value): string => $value);
}

final class ArrMapFixture
{
    public static function shout(string $value): string
    {
        return $value;
    }

    /** @return array<string, string> */
    public static function pair(string $value): array
    {
        return [$value => $value];
    }
}
--EXPECTF--
InvalidScalarArgument on line %d: Type int should be a subtype of string
