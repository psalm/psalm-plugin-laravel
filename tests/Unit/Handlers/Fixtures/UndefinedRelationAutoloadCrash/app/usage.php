<?php

declare(strict_types=1);

namespace AutoloadCrashFixture;

// Drives resolveBaseModel() -> concreteModel(), the pre-fix autoload site; the assignment drives
// ModelAggregateLoadHandler::singleModel() (#1652).
$target = DeprecatedOnLoad::with('posts');

// Drives resolveModelFromType() -> modelFromAtomic() -> isClassOrSubclassOf(), the other pre-fix
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

// CastResolver and ModelMetadataRegistryBuilder::classifyCast() (#1652). A plugin disabled at init (the
// migration's foreignIdFor()) fails these checks too.
function drive_casts(CastModel $model): void
{
    $status = $model->status;
    /** @psalm-check-type-exact $status = DeprecatedStatus */
    $shape = $model->toArray();
    /** @psalm-check-type-exact $shape = array{price?: int, status?: string, ...<string, mixed>} */
}
