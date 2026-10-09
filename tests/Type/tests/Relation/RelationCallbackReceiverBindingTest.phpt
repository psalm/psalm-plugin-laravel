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
     * @param string $relation
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

/** `self::class` binds to the declaring class on any receiver; `static::class` is the receiver's own class. */
function test_late_static_relation_on_a_subclass_receiver(): void
{
    Tool::query()->whereHas('lateBoundReplacement', function ($q): void {
        /** @psalm-check-type-exact $q = Builder<Tool> */
        $q->where('id', 1);
    });
    PowerTool::query()->whereHas('replacementTool', function ($q): void {
        /** @psalm-check-type-exact $q = Builder<Tool> */
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
