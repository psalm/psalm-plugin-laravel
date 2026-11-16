--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\DamageReport;
use App\Models\Supplier;
use App\Models\Vehicle;
use App\Models\WrappedModel;
use App\Builders\CallbackWrappingBuilder;
use App\Builders\CombiningBuilder;
use App\Models\CombiningModel;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;

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
 * A spread or computed key anywhere in the literal may hide a repeated path (or a union the shape would reject), so
 * no closure of the call is typed and no declared type is checked.
 *
 * @param array<string, string> $extra
 */
function test_partially_dynamic_literal(array $extra, string $key): void
{
    Customer::query()->with(['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }, ...$extra]);
    Customer::query()->with(['vehicles' => ['customer' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }], ...$extra]);
    Customer::query()->with([$key => 'x', 'vehicles' => function (BelongsTo $q): void {
        takes_mixed($q);
    }]);
    Customer::query()->with(['vehicles' => function (HasMany $q): void {
        takes_mixed($q);
    }, ...$extra]);
}

/**
 * A loop widens the receiver to a union on a later pass: the inference written on the first pass is revoked, so
 * the closure is not left with the first receiver's Relation (here `HasManyThrough`, which Vehicle's `HasMany`
 * is not).
 */
function test_loop_widening_revokes_earlier_inference(bool $flag): void
{
    $builder = Customer::query();

    while ($flag) {
        $builder->with(['workOrders' => function ($q): void {
            if ($q instanceof HasMany) {
                takes_mixed($q);
            }
        }]);

        $builder = Vehicle::query();
    }
}

/**
 * Every eager-load entry point ends in the builder's `with()`: a model whose builder overrides it, or a builder
 * whose inherited `withOnly()` delegates to the override, keeps its own contract.
 *
 * @param Collection<int, WrappedModel> $models
 * @param CallbackWrappingBuilder<WrappedModel> $builder
 */
function test_delegated_overrides_decline(WrappedModel $model, Collection $models, CallbackWrappingBuilder $builder): void
{
    WrappedModel::with(['parts' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
    $model->load(['parts' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
    $model->loadMissing(['parts' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
    $models->load(['parts' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
    $models->loadMissing(['parts' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
    $builder->withOnly(['parts' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
}

/**
 * An intersection dispatches through the base Builder, but its other component may override `with()`.
 *
 * @param Builder<Customer>&OverridingBuilder<Customer> $builder
 */
function test_builder_intersection_declines(Builder $builder): void
{
    $builder->with(['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
}

/** Laravel composes constraints that flatten to one path, so none of them receives the bare Relation. */
function test_colliding_flattened_paths_decline(): void
{
    Customer::query()->with([
        'vehicles' => ['customer' => function ($q): void {
            /** @psalm-check-type-exact $q = mixed */
            takes_mixed($q);
        }],
        'vehicles.customer' => function ($q): void {
            /** @psalm-check-type-exact $q = mixed */
            takes_mixed($q);
        },
    ]);
}

/** `loadMissing()` does not flatten nested arrays on every supported Laravel version: such closures are not typed. */
function test_load_missing_nested_arrays_decline(Customer $customer): void
{
    $customer->loadMissing(['vehicles' => ['workOrders' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]]);
    $customer->loadMissing(['vehicles.workOrders' => function ($q): void {
        /** @psalm-check-type-exact $q = HasMany<WorkOrder, Vehicle> */
        takes_mixed($q);
    }]);
}

/** A `:columns` suffix on a closure-valued key stays part of the relation name at runtime (RelationNotFoundException). */
function test_columns_on_closure_key_declines(): void
{
    Customer::query()->with(['vehicles:id' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
    Customer::query()->with('vehicles:id', function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    });
}

/** The string+callback form restates its Relation on every pass too: a widened receiver revokes the first pass. */
function test_loop_widening_revokes_string_callback_inference(bool $flag): void
{
    $builder = WorkOrder::query();

    while ($flag) {
        $builder->with('parts', function ($q): void {
            if ($q instanceof HasMany) {
                takes_mixed($q);
            }
        });

        $builder = Supplier::query();
    }
}

/** A nested constraint runs through the related model's builder: its `with()` override keeps its own contract. */
final class ParentOfWrapped extends Model
{
    /** @return HasMany<WrappedModel, $this> */
    public function wrapped(): HasMany
    {
        return $this->hasMany(WrappedModel::class);
    }
}

function test_nested_constraint_on_overridden_related_builder_declines(): void
{
    ParentOfWrapped::query()->with(['wrapped.parts' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
    ParentOfWrapped::query()->with(['wrapped' => ['parts' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]]);
}

/**
 * A collection that overrides the delegated `load()` keeps its own contract.
 *
 * @template TKey of array-key
 * @template TModel of Model
 * @extends Collection<TKey, TModel>
 */
final class OverridingCollection extends Collection
{
    /** @inheritDoc */
    #[\Override]
    public function load($relations)
    {
        parent::load($relations);

        return $this;
    }
}

/** @param OverridingCollection<int, Customer> $customers */
function test_collection_override_declines(OverridingCollection $customers): void
{
    $customers->load(['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
    $customers->loadMissing(['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
}

function test_string_callback_through_overridden_related_builder_declines(): void
{
    ParentOfWrapped::query()->with('wrapped.parts', function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    });
}

/** `loadMissing()` does not walk nested arrays, but a computed key inside a deeper one still declines the whole call. */
function test_load_missing_dynamic_nested_key_declines(Customer $customer, string $key): void
{
    $customer->loadMissing(['vehicles.workOrders' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }, 'vehicles' => ['customer' => [$key => 'x']]]);
}

/**
 * @template TModel of Model
 * @extends Builder<TModel>
 */
final class UnknownBuilderWithOverride extends Builder
{
    /** @inheritDoc */
    #[\Override]
    public function with($relations, $callback = null)
    {
        return parent::with($relations, $callback);
    }
}

final class UnknownBuilderGrandchild extends Model {}

/** `newEloquentBuilder()` with a docblock-only return type: the real builder (and its `with()`) is unknown. */
final class UnknownBuilderChild extends Model
{
    /**
     * @param \Illuminate\Database\Query\Builder $query
     * @return UnknownBuilderWithOverride<static>
     */
    #[\Override]
    public function newEloquentBuilder($query)
    {
        /** @var UnknownBuilderWithOverride<UnknownBuilderChild> $builder */
        $builder = new UnknownBuilderWithOverride($query);

        return $builder;
    }

    /** @return HasMany<UnknownBuilderGrandchild, $this> */
    public function grandchildren(): HasMany
    {
        return $this->hasMany(UnknownBuilderGrandchild::class);
    }
}

final class EagerUnknownBuilderOwner extends Model
{
    /** @return HasMany<UnknownBuilderChild, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(UnknownBuilderChild::class);
    }
}

/**
 * A builder the plugin cannot see is not assumed to be Laravel's: its `with()` may pass something else to the
 * constraint. Every entry point declines, and so does a dot path or nested array through such a model.
 *
 * @param Collection<int, UnknownBuilderChild> $children
 */
function test_unknown_builder_declines(UnknownBuilderChild $child, Collection $children, EagerUnknownBuilderOwner $owner): void
{
    UnknownBuilderChild::with(['grandchildren' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
    UnknownBuilderChild::query()->with(['grandchildren' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
    $child->load(['grandchildren' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
    $child->loadMissing(['grandchildren' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
    $children->load(['grandchildren' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
    $owner->children()->with(['grandchildren' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
    EagerUnknownBuilderOwner::query()->with(['children.grandchildren' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
    EagerUnknownBuilderOwner::query()->with(['children' => ['grandchildren' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]]);
    EagerUnknownBuilderOwner::query()->with('children.grandchildren', function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    });
}

/**
 * A declared `Relation` type is not narrowed to the first receiver's relation for good: the `with('parts', fn)` form
 * hands the closure straight to Psalm, which narrows the storage to the supplied relation, and a later pass over a
 * widened (union) receiver declines, so the declared type must come back.
 */
function test_loop_widening_restores_declared_callback_type(bool $flag): void
{
    $builder = WorkOrder::query();

    while ($flag) {
        $builder->with('parts', function (Relation $q): void {
            /** @psalm-check-type-exact $q = Relation<Model, Model, mixed> */
            takes_mixed($q);
            if ($q instanceof BelongsTo) {
                takes_mixed($q);
            }
        });

        $builder = Supplier::query();
    }
}

/** A trait body shares one closure storage between its consumers, including a class no params provider dispatches on. */
trait EagerLoadsVehiclesFromTrait
{
    public function loadVehiclesFromTrait(): void
    {
        $this->load(['vehicles' => function ($q): void {
            takes_mixed($q);
        }]);
    }
}

final class EagerTraitModelConsumer extends Customer
{
    use EagerLoadsVehiclesFromTrait;
}

/** Has its own `load()`: the Customer pass above typed the closure, this one must see it untyped again. */
final class EagerTraitUnrelatedConsumer
{
    use EagerLoadsVehiclesFromTrait;

    /** @param array<string, mixed> $relations */
    public function load(array $relations): void {}
}

/**
 * The Relation types are rebuilt from bare class names, so a model with template params would be typed with
 * arguments the receiver does not carry (`HasMany<Vehicle, GenericEagerOwner>` for a `GenericEagerOwner<int>`).
 *
 * @template TKey of int|string
 */
final class GenericEagerOwner extends Model
{
    /** @return HasMany<Vehicle, $this> */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }
}

/** @param Builder<GenericEagerOwner<int>> $builder */
function test_generic_model_owner_declines(Builder $builder): void
{
    $builder->with(['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
    GenericEagerOwner::with(['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
}

/**
 * A registered builder that overrides a method between `with()` and the constraint call (`CombiningBuilder` overrides
 * `combineConstraints()`) may call the constraint with anything: the receiver, the model's own builder and an
 * intermediate model's builder all decline.
 */
final class CombiningBuilderOwner extends Model
{
    /** @return HasMany<CombiningModel, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(CombiningModel::class);
    }
}

/** @param CombiningBuilder<Customer> $builder */
function test_overridden_builder_delegate_declines(CombiningBuilder $builder, CombiningModel $model): void
{
    $builder->with(['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
    CombiningModel::with(['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
    $model->load(['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
    CombiningBuilderOwner::query()->with(['children.vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
}

/** A model that overrides a method building its eager-load query keeps its own contract. */
final class OwnQueryModel extends Model
{
    /** @return HasMany<Vehicle, $this> */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    #[\Override]
    public function newQueryWithoutRelationships()
    {
        return parent::newQueryWithoutRelationships();
    }
}

function test_overridden_model_query_method_declines(OwnQueryModel $model): void
{
    $model->load(['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
}

/**
 * `loadMissing()` walks a relation chain through the collection's `loadMissingRelation()`: an override keeps its own
 * contract, and so does a collection the plugin cannot see (docblock-only `newCollection()`), while `load()` on that
 * model does not touch the collection and stays typed.
 *
 * @template TKey of array-key
 * @template TModel of Model
 * @extends Collection<TKey, TModel>
 */
final class ChainOverridingCollection extends Collection
{
    /** @inheritDoc */
    #[\Override]
    protected function loadMissingRelation(Collection $models, array $path)
    {
        parent::loadMissingRelation($models, $path);
    }
}

final class UnknownCollectionModel extends Model
{
    /** @return HasMany<Vehicle, $this> */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /*
     * Docblock-only on purpose: a native named return type would register the collection. Psalm reports the docblock
     * as less specific than the trait's `Collection<array-key, Model&static>` (expected below).
     */
    /**
     * @param array<array-key, Model> $models
     * @return ChainOverridingCollection<array-key, Model&static>
     */
    #[\Override]
    public function newCollection(array $models = [])
    {
        /** @var ChainOverridingCollection<array-key, Model&UnknownCollectionModel> $collection */
        $collection = new ChainOverridingCollection($models);

        return $collection;
    }
}

/** @param ChainOverridingCollection<int, Customer> $customers */
function test_overridden_collection_delegate_and_unknown_collection_decline(ChainOverridingCollection $customers, UnknownCollectionModel $model): void
{
    $customers->loadMissing(['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
    $model->loadMissing(['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
    $model->load(['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = HasMany<Vehicle, UnknownCollectionModel> */
        takes_mixed($q);
    }]);
}

/**
 * A Relation that overrides its forwarding `__call()` may hand the constraint its Builder.
 *
 * @extends HasMany<Vehicle, Customer>
 */
final class ForwardingHasMany extends HasMany
{
    /** @inheritDoc */
    #[\Override]
    public function __call($method, $parameters)
    {
        return parent::__call($method, $parameters);
    }
}

function test_overridden_relation_forwarding_declines(ForwardingHasMany $relation): void
{
    $relation->with(['workOrders' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
}

interface EagerMarker {}

/**
 * An intersection in the model param would be reduced to its class, dropping the interface a correctly annotated
 * callback keeps.
 *
 * @param Builder<Customer&EagerMarker> $builder
 * @param Collection<int, Customer&EagerMarker> $customers
 */
function test_model_intersections_decline(Builder $builder, Collection $customers): void
{
    $builder->with(['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
    $customers->load(['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
    $customers->loadMissing(['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
}

/** A by-reference param is valid for Laravel (`combineConstraints()` passes a local variable): never report a mismatch. */
function test_by_reference_param_declines(): void
{
    Customer::query()->with('vehicles', function (HasMany &$q): void {
        takes_mixed($q);
    });
    Customer::query()->with(['vehicles' => function (HasMany &$q): void {
        takes_mixed($q);
    }]);
    Customer::query()->with(['vehicles' => function (&$q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
}

/** A static `Model::withOnly()` goes through the model's magic forwarding: an override keeps its own contract. */
final class ForwardingModel extends Model
{
    /** @return HasMany<Vehicle, $this> */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /** @inheritDoc */
    #[\Override]
    public static function __callStatic($method, $parameters)
    {
        return parent::__callStatic($method, $parameters);
    }
}

function test_overridden_model_static_forwarding_declines(): void
{
    ForwardingModel::withOnly(['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        takes_mixed($q);
    }]);
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
LessSpecificImplementedReturnType on line %d: %s
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $q has no provided type
