<?php

declare(strict_types=1);

namespace AutoloadCrashFixture;

use AutoloadCrashFixture\AggregateAssignment\AggregateTargetMaker;
use AutoloadCrashFixture\AliasRelation\AliasedRelationOwner;
use AutoloadCrashFixture\AliasRelation\AliasedRelationTarget;
use AutoloadCrashFixture\Casts\CastsModel;
use AutoloadCrashFixture\Casts\DeprecatedDirectStatus;
use AutoloadCrashFixture\Container\DeprecatedBoundService;
use AutoloadCrashFixture\ForeignIdFor\Invoice;
use AutoloadCrashFixture\ToArrayCasts\Money;
use AutoloadCrashFixture\ToArrayCasts\ToArrayCastModel;

// Sites driven without a call here: ModelMethodReturn (registry warm-up of its model), ForeignIdFor
// (schema build at plugin init, see database/migrations).

// UndefinedModelRelationHandler: resolveBaseModel() -> concreteModel().
DeprecatedOnLoad::with('posts');

// UndefinedModelRelationHandler: resolveModelFromType() -> modelFromAtomic() -> isClassOrSubclassOf().
function drive_instance_path(DeprecatedOnLoadInstance $x): void
{
    $x->with('posts');
}

// RelationResolver's tier-2 dot-walk (relatedModel() -> extractRelatedFromReturnType() -> singleModel()),
// which UndefinedModelRelationHandler delegates to: the dot forces resolving deprecatedRel's target model
// from its return-type generic.
function drive_tier_two_dot_path(TierTwoModel $model): void
{
    $model->with('deprecatedRel.child');
}

// ModelAggregateLoadHandler::singleModel(): is the assigned value a model? (#1652)
function drive_assignment(): void
{
    $target = AggregateTargetMaker::make();
}

// CastResolver at registry warm-up: a never-loaded enum still types its cast.
function drive_casts(CastsModel $model): void
{
    $status = $model->status;
    /** @psalm-check-type-exact $status = DeprecatedDirectStatus|null */
}

// ClassLineage: a relation return type written as a class_alias() name resolves, as is_a() did.
function drive_aliased_relation(AliasedRelationOwner $owner): void
{
    $child = $owner->child;
    /** @psalm-check-type-exact $child = AliasedRelationTarget|null */
}

// ContainerResolver: an unresolvable abstract that names a class, and a binding resolving to a class-name string.
function drive_container(): void
{
    $service = app('AutoloadCrashFixture\Container\DeprecatedService');

    $bound = app('service.class');
    /** @psalm-check-type-exact $bound = DeprecatedBoundService */
}

// SchemaAggregator: the table with a foreignIdFor() column still parses.
function drive_schema_column(Invoice $invoice): void
{
    $number = $invoice->number;
    /** @psalm-check-type-exact $number = string */
}

// ModelMetadataRegistryBuilder::classifyCast(): a never-loaded enum cast serializes to its backing value;
// a never-loaded caster keeps its type over the accessor.
function serialize_casts(ToArrayCastModel $model): void
{
    $shape = $model->toArray();
    /** @psalm-check-type-exact $shape = array{price?: Money, status?: string, ...<string, mixed>} */
}
