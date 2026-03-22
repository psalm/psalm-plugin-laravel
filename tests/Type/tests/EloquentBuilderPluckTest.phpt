--FILE--
<?php declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * pluck() on Builder should infer value type from model @property annotations.
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/486
 */

/** User has @property string $id — pluck should return Collection<int, string> */
function test_pluck_with_known_property(): void
{
    $_result = User::query()->pluck('id');
    /** @psalm-check-type-exact $_result = Collection<int, string> */
}

/** User has @property CarbonInterface|null $email_verified_at */
function test_pluck_with_nullable_property(): void
{
    $_result = User::query()->pluck('email_verified_at');
    /** @psalm-check-type-exact $_result = Collection<int, \Carbon\CarbonInterface|null> */
}

/** When the column name is not a known @property, fall back to default behavior */
function test_pluck_with_unknown_column(): void
{
    $_result = User::query()->pluck('unknown_column');
    /** @psalm-check-type-exact $_result = Collection<array-key, mixed> */
}

/** When the column is a variable (not a string literal), fall back to default */
function test_pluck_with_dynamic_column(string $column): void
{
    $_result = User::query()->pluck($column);
    /** @psalm-check-type-exact $_result = Collection<array-key, mixed> */
}

/**
 * Key column uses array-key because Laravel does NOT apply casts to key columns —
 * keys come from raw PDO results and are always string|int.
 */
function test_pluck_with_key_column(): void
{
    $_result = User::query()->pluck('email_verified_at', 'id');
    /** @psalm-check-type-exact $_result = Collection<array-key, \Carbon\CarbonInterface|null> */
}

/** pluck with an unknown key column should still use array-key for keys */
function test_pluck_with_unknown_key_column(): void
{
    $_result = User::query()->pluck('id', 'unknown_key');
    /** @psalm-check-type-exact $_result = Collection<array-key, string> */
}

/** Template type should be preserved through chained Builder methods */
function test_pluck_after_where(): void
{
    $_result = User::query()->where('active', true)->pluck('id');
    /** @psalm-check-type-exact $_result = Collection<int, string> */
}
?>
--EXPECTF--
