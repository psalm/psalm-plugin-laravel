--FILE--
<?php declare(strict_types=1);

use App\Builders\MechanicBuilder;
use App\Builders\VehicleBuilder;
use App\Builders\WorkOrderBuilder;
use App\Models\AbstractDocument;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Mechanic;
use App\Models\Part;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * How the callback's relation is resolved: its CLASS (is it a MorphTo?) is read separately from its
 * related-model inference, and the related model comes from RelationResolver, as the relation-name rule
 * does, so every shape that resolver understands types `$q`.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1676
 */

/** A relation whose generic is declared but whose body the parser cannot read (`$r = ...; return $r;`). */
class LocalOwner extends Customer
{
    /** @return HasMany<Vehicle, Customer> */
    public function localVehicles(): HasMany
    {
        $relation = $this->hasMany(Vehicle::class);

        return $relation;
    }
}

/** The parser pins `static::class` to the declaring class; a delegating relation inherits that pin. */
class PeerBase extends \Illuminate\Database\Eloquent\Model
{
    public function peers(): HasMany
    {
        return $this->peerHelper();
    }

    protected function peerHelper(): HasMany
    {
        return $this->hasMany(static::class);
    }
}

final class PeerChild extends PeerBase
{
    /** A child-declared wrapper around the inherited, delegating relation. */
    public function wrappedPeers(): HasMany
    {
        return $this->peers();
    }
}

/** A body the parser cannot read, declared as two alternatives that target different models. */
class AmbiguousOwner extends Customer
{
    /** @return HasMany<Vehicle, Customer>|HasOne<WorkOrder, Customer> */
    public function either(): HasMany|HasOne
    {
        $relation = $this->hasMany(Vehicle::class);

        return $relation;
    }
}

/** A MorphTo with no generic annotation (`Shop::shopable(): MorphTo`) is still a direct MorphTo. */
function test_unannotated_morph_to_takes_the_literal_types(): void
{
    Shop::query()->whereHasMorph('shopable', [Vehicle::class, WorkOrder::class], function ($q, $type): void {
        /** @psalm-check-type-exact $q = VehicleBuilder<Vehicle>|WorkOrderBuilder<WorkOrder> */
        /** @psalm-check-type-exact $type = class-string<Vehicle>|class-string<WorkOrder> */
        $q->where('type', $type);
    });
}

/** A trait-hosted relation resolves against the composing model, not the trait. */
function test_trait_hosted_relation(): void
{
    WorkOrder::query()->whereHas('revisions', function ($q): void {
        /** @psalm-check-type-exact $q = WorkOrderBuilder<WorkOrder> */
        $q->whereCompleted();
    });
    WorkOrder::query()->whereHas('revisionAuthor', function ($q): void {
        /** @psalm-check-type-exact $q = MechanicBuilder<Mechanic> */
        $q->where('id', 1);
    });
    WorkOrder::query()->whereHas('revisions.revisions', function ($q): void {
        /** @psalm-check-type-exact $q = WorkOrderBuilder<WorkOrder> */
        $q->whereCompleted();
    });
    WorkOrder::query()->withWhereHas('revisions', function ($q): void {
        /** @psalm-check-type-exact $q = WorkOrderBuilder<WorkOrder>|HasMany<WorkOrder, WorkOrder> */
        $q->where('id', 1);
    });
}

/**
 * A trait composed by a PARENT binds `self` to that parent. On a subclass receiver that related model (an
 * ancestor of the receiver) is indistinguishable from a `static::class` leak, so it declines.
 */
function test_trait_on_parent_model_declines(): void
{
    Contract::query()->whereHas('revisions', function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        $q->where('id', 1);
    });
    Contract::query()->withWhereHas('revisions', function ($q): void {
        /** @psalm-check-type-exact $q = Builder<Illuminate\Database\Eloquent\Model>|Relation<Illuminate\Database\Eloquent\Model, Illuminate\Database\Eloquent\Model, mixed> */
        $q->where('id', 1);
    });
}

/**
 * `documentParts()` is a bare native `HasMany` declared on the abstract parent: the body names the related
 * model, and the receiver's own class is the Relation's parent model.
 */
function test_native_only_relation_inherited_from_parent(): void
{
    Contract::query()->whereHas('documentParts', function ($q): void {
        /** @psalm-check-type-exact $q = Builder<Part> */
        $q->where('id', 1);
    });
    Contract::query()->withWhereHas('documentParts', function ($q): void {
        /** @psalm-check-type-exact $q = Builder<Part>|HasMany<Part, Contract> */
        $q->where('id', 1);
    });
}

/** Two declared alternatives name no single related model or relation class: the first must not win. */
function test_declared_union_of_relations_declines(): void
{
    AmbiguousOwner::query()->whereHas('either', function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        $q->where('id', 1);
    });
}

/** A related model that is an ancestor of a subclass receiver may be a `static::class` leak, however it is reached. */
function test_ancestor_related_model_declines_on_delegation(): void
{
    PeerChild::query()->whereHas('peers', function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        $q->where('id', 1);
    });
    PeerChild::query()->whereHas('wrappedPeers', function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        $q->where('id', 1);
    });
}

/** Two composed traits declare `revisions()` (`insteadof`): the parser declines, so the callback keeps the stub. */
function test_ambiguous_trait_relation_declines(): void
{
    Supplier::query()->whereHas('revisions', function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        $q->where('id', 1);
    });
}

/** A relation declared on a PARENT model binds the subclass receiver as the Relation's parent model. */
function test_relation_inherited_from_parent_model(): void
{
    LocalOwner::query()->withWhereHas('vehicles', function ($q): void {
        /** @psalm-check-type-exact $q = VehicleBuilder<Vehicle>|HasMany<Vehicle, LocalOwner> */
        $q->where('year', 2020);
    });
}

/** A declared generic is enough for the related model, even when the body is not a plain factory call. */
function test_declared_generic_relation(): void
{
    LocalOwner::query()->whereHas('localVehicles', function ($q): void {
        /** @psalm-check-type-exact $q = VehicleBuilder<Vehicle> */
        $q->whereElectric();
    });
    LocalOwner::query()->whereHas('localVehicles.workOrders', function ($q): void {
        /** @psalm-check-type-exact $q = WorkOrderBuilder<WorkOrder> */
        $q->whereCompleted();
    });
}

/** The eager-load slot needs the Relation type itself, which the unparseable body cannot give: it declines. */
function test_eager_load_without_a_parsed_relation_type_declines(): void
{
    LocalOwner::query()->withWhereHas('localVehicles', function ($q): void {
        /** @psalm-check-type-exact $q = Builder<Illuminate\Database\Eloquent\Model>|Relation<Illuminate\Database\Eloquent\Model, Illuminate\Database\Eloquent\Model, mixed> */
        $q->whereElectric();
    });
}
?>
--EXPECTF--
MissingClosureParamType on line %d: Parameter $q has no provided type
MixedMethodCall on line %d: Cannot determine the type of $q when calling method where
MissingClosureParamType on line %d: Parameter $q has no provided type
MixedMethodCall on line %d: Cannot determine the type of $q when calling method where
MissingClosureParamType on line %d: Parameter $q has no provided type
MixedMethodCall on line %d: Cannot determine the type of $q when calling method where
MissingClosureParamType on line %d: Parameter $q has no provided type
MixedMethodCall on line %d: Cannot determine the type of $q when calling method where
MissingClosureParamType on line %d: Parameter $q has no provided type
MixedMethodCall on line %d: Cannot determine the type of $q when calling method where
