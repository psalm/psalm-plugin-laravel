--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/929
 * It documents existing issue as is, ideally this test should have zero expected Psalm output.
 * There is https://github.com/psalm/psalm-plugin-laravel/pull/936 but we are looking for a more ellegant solution.
 *
 * Tests for SoftDeletingScope-registered macros that are NOT declared as
 * @method static on the SoftDeletes trait: restore() on Builder instances.
 *
 * Also covers restoreOrCreate() and createOrRestore() which ARE declared as
 * @method static on the trait but only for static-on-model calls; their
 * Builder-instance forms come through __call -> macro lookup and are not
 * intercepted by the plugin because extractBuilderReturningMethods filters
 * to methods whose return type is a Builder (these return the model).
 *
 * Laravel source: Illuminate\Database\Eloquent\SoftDeletingScope::addRestore / addRestoreOrCreate / addCreateOrRestore.
 */

// --- restore() on builder instance: should return int (count of restored rows) ---

function test_restore_on_query_returns_int(): void
{
    $_result = Customer::query()->restore();
    /** @psalm-check-type-exact $_result = int */
}

function test_restore_with_only_trashed_returns_int(): void
{
    $_result = Customer::query()->onlyTrashed()->where('id', 1)->restore();
    /** @psalm-check-type-exact $_result = int */
}

// --- restoreOrCreate() on builder instance: should return Customer model ---

function test_restore_or_create_on_query_returns_model(): void
{
    $_result = Customer::query()->restoreOrCreate(['email' => 'a@b.c']);
    /** @psalm-check-type-exact $_result = Customer */
}

// --- createOrRestore() on builder instance: should return Customer model ---

function test_create_or_restore_on_query_returns_model(): void
{
    $_result = Customer::query()->createOrRestore(['email' => 'a@b.c']);
    /** @psalm-check-type-exact $_result = Customer */
}

// --- static-on-model forms (these MAY work via SoftDeletes trait @method static) ---

function test_restore_or_create_static_on_model(): void
{
    $_result = Customer::restoreOrCreate(['email' => 'a@b.c']);
    /** @psalm-check-type-exact $_result = Customer */
}

function test_create_or_restore_static_on_model(): void
{
    $_result = Customer::createOrRestore(['email' => 'a@b.c']);
    /** @psalm-check-type-exact $_result = Customer */
}

?>
--EXPECTF--
CheckType on line %d: Checked variable $_result = int does not match $_result = mixed
CheckType on line %d: Checked variable $_result = int does not match $_result = mixed
CheckType on line %d: Checked variable $_result = App\Models\Customer does not match $_result = mixed
CheckType on line %d: Checked variable $_result = App\Models\Customer does not match $_result = mixed
CheckType on line %d: Checked variable $_result = App\Models\Customer does not match $_result = App\Models\Customer&static
CheckType on line %d: Checked variable $_result = App\Models\Customer does not match $_result = App\Models\Customer&static