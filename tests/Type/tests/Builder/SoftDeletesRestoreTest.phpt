--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\Part;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tests for SoftDeletingScope-registered macros that are NOT declared as
 * @method static on the SoftDeletes trait: restore() on Builder instances.
 *
 * Also covers restoreOrCreate() and createOrRestore() which ARE declared as
 * @method static on the trait but only for static-on-model calls; their
 * Builder-instance forms come through __call -> macro lookup and are not
 * intercepted by the plugin because extractBuilderReturningMethods filters
 * to methods whose return type is a Builder (these return the model).
 *
 * Laravel source: Illuminate\Database\Eloquent\SoftDeletingScope::addRestore /
 * addRestoreOrCreate / addCreateOrRestore.
 *
 * Compare Larastan EloquentBuilderForwardsCallsExtension:96-129 which hardcodes:
 *   restore         → int
 *   restoreOrCreate → TModel
 *   createOrRestore → TModel
 */

// --- restore() on builder instance: should return int (count of restored rows) ---

function test_restore_on_query_returns_int(): void
{
    $_result = Customer::query()->restore();
    /** @psalm-check-type-exact $_result = int */
}

// --- restore() on a model instance is a real method on the SoftDeletes trait that returns
// bool. Resolved by Psalm normally; the Builder-instance handler must NOT shadow it. ---

function test_restore_on_model_instance_returns_bool(Customer $customer): void
{
    $_result = $customer->restore();
    /** @psalm-check-type-exact $_result = bool */
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

// --- zero-arg form exercises both default values declared on the synthesized params ---

function test_restore_or_create_with_no_args_returns_model(): void
{
    $_result = Customer::query()->restoreOrCreate();
    /** @psalm-check-type-exact $_result = Customer */
}

// --- createOrRestore() on builder instance: should return Customer model ---

function test_create_or_restore_on_query_returns_model(): void
{
    $_result = Customer::query()->createOrRestore(['email' => 'a@b.c']);
    /** @psalm-check-type-exact $_result = Customer */
}

// --- static-on-model forms work via the SoftDeletes trait's @method static restoreOrCreate /
// createOrRestore declarations (return type `static`, which Psalm reports as `Customer&static`
// per its standard handling of trait-declared @method static). ---

function test_restore_or_create_static_on_model(): void
{
    $_result = Customer::restoreOrCreate(['email' => 'a@b.c']);
    /** @psalm-check-type-exact $_result = Customer&static */
}

function test_create_or_restore_static_on_model(): void
{
    $_result = Customer::createOrRestore(['email' => 'a@b.c']);
    /** @psalm-check-type-exact $_result = Customer&static */
}

// --- non-SoftDeletes model: handler must NOT narrow restore() to int. Part does not
// use the SoftDeletes trait, so the registry guard in BuilderScopeHandler should
// short-circuit and let Builder::__call's mixed return surface. Locks in the guard
// against a future refactor that drops the model gate. ---

function test_restore_on_non_soft_deletes_model_is_not_narrowed(): void
{
    $_result = Part::query()->restore();
    /** @psalm-check-type-exact $_result = mixed */
}

// --- arity check: restore() takes no args. Validates that the synthesized params from
// BuilderScopeHandler::softDeletesMacroParams are picked up by Psalm's checkMethodArgs
// and surface a TooManyArguments diagnostic on excess arguments. ---

function test_restore_rejects_extra_arguments(): void
{
    Customer::query()->restore('bogus');
}

?>
--EXPECTF--
TooManyArguments on line %d: Too many arguments for Illuminate\Database\Eloquent\Builder::restore - expecting 0 but saw 1
