--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\DamageReport;
use App\Models\PowerTool;
use App\Models\Receipt;
use App\Models\Secret;
use App\Models\Supplier;
use App\Models\Tool;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\QueriesRelationships;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What the callback's receiver binds to: the model of a generic custom builder, Laravel's own signature (a
 * userland override keeps its contract), late-static relation bodies, and morph-map keys written as `::class`.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1676
 */

/**
 * The model is the argument the subclass forwards to `Builder::TModel`, wherever it sits among its own templates.
 *
 * @template TExtra
 * @template TModel of \Illuminate\Database\Eloquent\Model
 * @extends Builder<TModel>
 */
final class TrailingModelBuilder extends Builder {}

/**
 * @template TModel of \Illuminate\Database\Eloquent\Model
 * @template TExtra
 * @extends Builder<TModel>
 */
final class LeadingModelBuilder extends Builder {}

/**
 * A userland override owns its contract: this one declares a plain `Closure`, which it calls with a label too.
 *
 * @extends Builder<Customer>
 */
final class LabellingBuilder extends Builder
{
    /**
     * @param \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>|string $relation
     * @param string $operator
     * @param int|\Illuminate\Contracts\Database\Query\Expression $count
     * @return $this
     */
    #[\Override]
    public function whereHas($relation, ?Closure $callback = null, $operator = '>=', $count = 1)
    {
        return $this;
    }
}

/**
 * A transparent override without docblocks inherits Psalm's own parameter types from the parent.
 *
 * @extends Builder<Customer>
 */
final class TransparentBuilder extends Builder
{
    #[\Override]
    public function whereHas($relation, ?Closure $callback = null, $operator = '>=', $count = 1)
    {
        return $this;
    }
}

/**
 * @template TModel of \Illuminate\Database\Eloquent\Model
 * @extends Builder<TModel>
 */
class IntermediateBuilder extends Builder {}

/**
 * The child's own `TModel` is a different slot from the intermediate's: the forwarded template is followed by
 * its defining class, not matched by name.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 * @template TActual of \Illuminate\Database\Eloquent\Model
 * @extends IntermediateBuilder<TActual>
 */
final class DecoyChildBuilder extends IntermediateBuilder {}

/**
 * @template TDecoy of \Illuminate\Database\Eloquent\Model
 * @template TRelated of \Illuminate\Database\Eloquent\Model
 * @extends HasMany<TRelated, Customer>
 */
final class DecoyRelation extends HasMany {}

trait WrapsWhereHas
{
    public function whereHas($relation, ?Closure $callback = null, $operator = '>=', $count = 1)
    {
        return $this;
    }
}

/**
 * Psalm ignores `insteadof` (vimeo/psalm#12113) and still names Laravel's trait as the declaring one.
 *
 * @extends Builder<Customer>
 */
final class TraitOverrideBuilder extends Builder
{
    use QueriesRelationships, WrapsWhereHas {
        WrapsWhereHas::whereHas insteadof QueriesRelationships;
    }
}

/** @param DecoyChildBuilder<Supplier, Customer> $builder */
function test_forwarded_template_is_followed_by_defining_class(DecoyChildBuilder $builder): void
{
    $builder->whereHas('vehicles', function ($q): void {
        /** @psalm-check-type-exact $q = App\Builders\VehicleBuilder<Vehicle> */
        $q->whereElectric();
    });
}

/** @param DecoyRelation<Supplier, Vehicle> $relation */
function test_custom_relation_projects_its_related_model(DecoyRelation $relation): void
{
    $relation->whereHas('workOrders', function ($q): void {
        /** @psalm-check-type-exact $q = App\Builders\WorkOrderBuilder<App\Models\WorkOrder> */
        $q->whereCompleted();
    });
}

function test_trait_override_keeps_its_own_contract(TraitOverrideBuilder $builder): void
{
    $builder->whereHas('vehicles', function ($q): void {
        /** @psalm-check-type-exact $q = Builder<Illuminate\Database\Eloquent\Model> */
        $q->where('id', 1);
    });
}

/** @param TrailingModelBuilder<Supplier, Customer> $builder */
function test_model_is_not_the_first_template(TrailingModelBuilder $builder): void
{
    $builder->whereHas('vehicles', function ($q): void {
        /** @psalm-check-type-exact $q = App\Builders\VehicleBuilder<Vehicle> */
        $q->whereElectric();
    });
}

/** @param LeadingModelBuilder<Customer, Supplier> $builder */
function test_model_is_the_first_template(LeadingModelBuilder $builder): void
{
    $builder->whereHas('vehicles', function ($q): void {
        /** @psalm-check-type-exact $q = App\Builders\VehicleBuilder<Vehicle> */
        $q->whereElectric();
    });
}

/** The decoy is not a model: no single model can be read, so the call keeps the stub. */
function test_non_model_template_declines(): void
{
    /** @var TrailingModelBuilder<Customer, Supplier> $builder */
    $builder = Customer::query();
    $builder->whereHas('vehicles', function ($q): void {
        /** @psalm-check-type-exact $q = Builder<Illuminate\Database\Eloquent\Model> */
        $q->where('id', 1);
    });
}

function test_userland_override_keeps_its_own_contract(LabellingBuilder $builder): void
{
    $builder->whereHas('vehicles', function (Builder $q, string $label): void {
        $q->where('label', $label);
    });
}

function test_transparent_override_keeps_inherited_param_types(TransparentBuilder $builder): void
{
    $builder->whereHas('vehicles', function (Builder $q): void {
        $q->where('id', 1);
    }, [], 'wrong');
}

/**
 * `static::class` binds to the receiver's class, which the parser pins to the declaring class. A related model that
 * is the declaring class or an ancestor of a subclass receiver may be that leak (`self::class` is, harmlessly,
 * declined with it); on the declaring class itself the pin is exact.
 */
function test_late_static_relation_on_a_subclass_receiver(): void
{
    Tool::query()->whereHas('lateBoundReplacement', function ($q): void {
        /** @psalm-check-type-exact $q = Builder<Tool> */
        $q->where('id', 1);
    });
    PowerTool::query()->whereHas('replacementTool', function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        $q->where('id', 1);
    });
    PowerTool::query()->whereHas('lateBoundReplacement', function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        $q->where('id', 1);
    });
}

/** A morph-map key written as `Secret::class` still resolves through the map, exactly like an alias. */
function test_morph_map_applies_to_class_literals(): void
{
    // Psalm scans a class once code names it; the map target is otherwise only reachable through the string key.
    takes_string(Receipt::class);
    DamageReport::query()->whereHasMorph('reportable', [Secret::class], function ($q, $type): void {
        /** @psalm-check-type-exact $type = class-string<Receipt> */
        $q->where('type', $type);
    });
}

function takes_string(string $_value): void {}
?>
--EXPECTF--
MissingParamType on line %d: Parameter $relation has no provided type
InvalidArgument on line %d: Argument 3 of TransparentBuilder::whereHas expects string, but array<never, never> provided
InvalidCast on line %d: array<never, never> cannot be cast to string
InvalidArgument on line %d: Argument 4 of TransparentBuilder::whereHas expects Illuminate\Contracts\Database\Query\Expression|int, but 'wrong' provided
MissingClosureParamType on line %d: Parameter $q has no provided type
MixedMethodCall on line %d: Cannot determine the type of $q when calling method where
MissingClosureParamType on line %d: Parameter $q has no provided type
MixedMethodCall on line %d: Cannot determine the type of $q when calling method where
