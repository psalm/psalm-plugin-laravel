--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\DirectScopeModel;
use Illuminate\Database\Eloquent\Builder;

/**
 * A first-class callable of a call Laravel forwards through `__callStatic` / `__call` (#1660). Psalm 7.0
 * crashed ("Cannot get method params", `$storage should not be null`); these shapes now analyse. The
 * inferred closures are NOT the runtime ones, which pins today's behavior, not a goal:
 *
 * - `Customer::where(...)` is a closure over `Model::__callStatic('where', ...)`: variadic, returning the
 *   Builder. Psalm infers the Builder method's own signature and `Customer&static` as the return.
 * - `Customer::verified(...)` is a PUBLIC #[Scope]: PHP throws `Error` (non-static method) when the closure is
 *   created. Psalm infers the scope's own signature without an issue.
 * - `DirectScopeModel::active(...)` (protected #[Scope]) is a `__callStatic` closure returning the Builder.
 *   Psalm infers `(Builder<DirectScopeModel>): void`, the signature of the scope method.
 * - `$model->active(...)` from outside the class reports UndefinedMagicMethod: Psalm's first-class-callable path
 *   for an inaccessible method only consults `@method` pseudo-methods. The same call written as
 *   `$model->active()` is forwarded and typed (see ProtectedScopeSurfacesTest).
 *
 * A dynamic `Customer::whereEmailVerifiedAt(...)` and a legacy `Customer::ofName(...)` still crash Psalm and
 * are not covered here.
 */
function where_callable(): void
{
    $_callable = Customer::where(...);
    /** @psalm-check-type-exact $_callable = Closure[impure](Closure[impure](Builder<Customer&static>):mixed|Illuminate\Contracts\Database\Query\Expression|array<array-key, mixed>|string, mixed|null=, mixed|null=, string=):Customer&static */
}

function public_attribute_scope_callable(): void
{
    $_callable = Customer::verified(...);
    /** @psalm-check-type-exact $_callable = Closure[impure](Builder):void */
}

function protected_attribute_scope_static_callable(): void
{
    $_callable = DirectScopeModel::active(...);
    /** @psalm-check-type-exact $_callable = Closure[impure](Builder<DirectScopeModel>):void */
}

function protected_attribute_scope_instance_callable(DirectScopeModel $model): void
{
    $_callable = $model->active(...);
    /** @psalm-check-type-exact $_callable = Closure[impure] */
}
?>
--EXPECTF--
UndefinedMagicMethod on line %d: Magic method App\Models\DirectScopeModel::active does not exist
