--FILE--
<?php declare(strict_types=1);

use App\Models\AbstractSoftDeletable;
use App\Models\ConcreteSoftDeletable;
use App\Models\SoftDeletableBuilder;
use Illuminate\Database\Eloquent\Builder;

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/901
 *
 * Regression guard for the builder half of #901. AbstractSoftDeletable declares a custom Eloquent
 * builder AND composes SoftDeletes (mirroring BookStack's Entity). SoftDeletes contributes the
 * withTrashed()/onlyTrashed() @method pseudo-methods; custom builder detection
 * (handleTraitBuilderMethods) strips them so they resolve through the custom builder instead.
 *
 * An abstract base is commonly queried through the base Builder<AbstractSoftDeletable>, from which
 * BuilderScopeHandler resolves those pseudo-methods; running custom builder detection on the
 * abstract base would strip them and regress `Builder<AbstractSoftDeletable>->onlyTrashed()` to a
 * mixed call (observed on BookStack's TrashCan/SearchIndex). ModelRegistrationHandler skips custom
 * builder detection for abstract bases, so the model pseudo-methods survive; concrete children
 * still detect the inherited custom builder.
 */

/** Abstract base queried via base Builder: the SoftDeletes pseudo-methods still resolve (not mixed). */
function soft_deletes_on_abstract_typed_base_builder(AbstractSoftDeletable $instance): void
{
    /** @var Builder<AbstractSoftDeletable> $query */
    $query = $instance->newQuery();
    $_only = $query->onlyTrashed();
    /** @psalm-check-type-exact $_only = Builder<AbstractSoftDeletable> */
    $_count = $query->withTrashed()->count();
    /** @psalm-check-type-exact $_count = int<0, max> */
}

/** Concrete child still detects the inherited custom builder (the gate did not regress it). */
function custom_builder_resolves_on_concrete_child(): void
{
    $_q = ConcreteSoftDeletable::query();
    /** @psalm-check-type-exact $_q = SoftDeletableBuilder<ConcreteSoftDeletable> */
    $_only = ConcreteSoftDeletable::query()->onlyTrashed();
    /** @psalm-check-type-exact $_only = SoftDeletableBuilder<ConcreteSoftDeletable> */
}
?>
--EXPECTF--
