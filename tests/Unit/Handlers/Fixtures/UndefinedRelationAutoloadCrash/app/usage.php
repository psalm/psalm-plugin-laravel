<?php

declare(strict_types=1);

namespace AutoloadCrashFixture;

// Drives resolveBaseModel() -> concreteModel(), the pre-fix autoload site.
DeprecatedOnLoad::with('posts');

// Drives resolveModelFromType() -> modelFromAtomic() -> ClassLineage::isA(), the other pre-fix
// autoload site.
function drive_instance_path(DeprecatedOnLoadInstance $x): void
{
    $x->with('posts');
}

// Drives RelationResolver's tier-2 dot-walk (relatedModel() -> extractRelatedFromReturnType() ->
// singleModel()) — the autoload sites #1253 fixed in UndefinedModelRelationHandler but missed in this
// resolver it delegates to. The dot forces resolving deprecatedRel's target model from its return-type
// generic, autoloading DeprecatedTierTwoRelated pre-fix.
function drive_tier_two_dot_path(TierTwoModel $model): void
{
    $model->with('deprecatedRel.child');
}

// Drives ModelAggregateLoadHandler's assignment tracking (singleModel()), an autoload site of #1652.
function drive_aggregate_assignment(): void
{
    $x = DeprecatedOnLoadAssigned::make();
}
