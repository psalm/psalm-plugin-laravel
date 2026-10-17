--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\DamageReport;
use App\Models\Supplier;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/*
 * Eager-load constraint closures stay as today (`$q` is mixed) wherever the key or the receiver does not name
 * exactly one known Relation. Each case would change if its own decline gate were removed.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1945
 */

function takes_mixed(mixed $_value): void {}

/** A computed key, an unknown segment, an empty segment and a segment of a non-relation. */
function test_unresolvable_keys_decline(string $key): void
{
    Customer::query()->with([$key => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
    Customer::query()->with(['missingRelation' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
    Customer::query()->with(['vehicles.missingRelation' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
    Customer::query()->with(['vehicles..workOrders' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
    Customer::query()->with(['vehicles' => ['missingRelation' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]]);
}

/** A MorphTo has no single related model: neither mid-path nor as the constrained relation. */
function test_morph_to_declines(): void
{
    DamageReport::query()->with(['reportable.workOrders' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
    DamageReport::query()->with(['reportable' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
    DamageReport::query()->with(['reportable' => ['workOrders' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]]);
}

/** A union receiver re-analyzes the call per atomic, so no single Relation can be named. */
function test_union_receivers_decline(bool $flag, Customer|Supplier $either): void
{
    $builder = $flag ? Customer::query() : Supplier::query();
    $builder->with(['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
    $either->load(['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
}

/**
 * @template TModel of Model
 * @param TModel $model
 * @param Collection<int, Model> $models
 * @param Collection $bare
 * @param Collection<int, Customer>|Collection<int, Supplier> $either
 */
function test_unknown_models_decline(Model $model, Collection $models, Collection $bare, Collection $either): void
{
    $model->load(['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
    $models->load(['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
    $bare->load(['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
    $either->loadMissing(['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
}

/** Unpacked arguments cannot be mapped to parameters. */
function test_unpacked_arguments_decline(): void
{
    Customer::query()->with(...[['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]]);
}

/** `with($name, fn)` needs a literal relation name; `Model::with('a', fn)` is `func_get_args()`, the closure is never applied. */
function test_string_and_callback_declines(string $name): void
{
    Customer::query()->with($name, function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    });
    Customer::query()->with('missingRelation', function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    });
    Customer::with('vehicles', function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    });
}

/** Closures that are not literals, and `static`/`self` static calls, name nothing to type. */
class EagerStaticCallCustomer extends Customer
{
    public function staticCalls(): void
    {
        static::with(['vehicles' => function ($q): void {
            /** @psalm-check-type-exact $q = mixed */
            takes_mixed($q);
        }]);
        self::with(['vehicles' => function ($q): void {
            /** @psalm-check-type-exact $q = mixed */
            takes_mixed($q);
        }]);
    }
}

/**
 * A userland override keeps its own contract: the model's `load()` and the builder's `with()` are not Laravel's.
 *
 * @template TModel of Model
 * @extends Builder<TModel>
 */
final class OverridingBuilder extends Builder
{
    /** @inheritDoc */
    #[\Override]
    public function with($relations, $callback = null)
    {
        parent::with($relations, $callback);

        return $this;
    }
}

final class OverridingModel extends Model
{
    /** @return HasMany<Vehicle, $this> */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /** @inheritDoc */
    #[\Override]
    public function load($relations)
    {
        parent::load($relations);

        return $this;
    }
}

/** @param OverridingBuilder<Customer> $builder */
function test_userland_overrides_decline(OverridingBuilder $builder, OverridingModel $model): void
{
    $builder->with(['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
    $model->load(['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
}

/**
 * A spread or computed key elsewhere in the literal does not stop typing of its literal closure entries, but the
 * params answer declines: the declared `BelongsTo` is not reported (the shape would otherwise reject the literal's
 * unknown part), and the compatible `HasMany` raises no coercion either.
 *
 * @param array<string, string> $extra
 */
function test_partially_dynamic_literal(array $extra, string $key): void
{
    Customer::query()->with(['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = HasMany<Vehicle, Customer> */
        takes_mixed($q);
    }, ...$extra]);
    Customer::query()->with([$key => 'x', 'vehicles' => function (BelongsTo $q): void {
        takes_mixed($q);
    }]);
    Customer::query()->with(['vehicles' => function (HasMany $q): void {
        takes_mixed($q);
    }, ...$extra]);
}
?>
--EXPECTF--
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $q has no provided type
