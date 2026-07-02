--FILE--
<?php declare(strict_types=1);

use App\Builders\CovariantNonFinalCustomBuilder;
use App\Builders\NonFinalCustomBuilder;
use App\Models\CovariantNonFinalCustomBuilderModel;
use App\Models\NonFinalCustomBuilderModel;

/**
 * @param NonFinalCustomBuilder<NonFinalCustomBuilderModel> $builder
 */
function accepts_invariant_builder_for_exact_model(NonFinalCustomBuilder $builder): void
{
    $_model = $builder->getModel();
    /** @psalm-check-type-exact $_model = NonFinalCustomBuilderModel */
}

/**
 * Non-final invariant custom-builder models keep the exact model template so assigning
 * the result to invariant `CustomBuilder<Model>` consumers does not become a false positive.
 */
function invariant_custom_builder_instance_query_methods_use_exact_model(NonFinalCustomBuilderModel $model): void
{
    $_newQuery = $model->newQuery();
    /** @psalm-check-type-exact $_newQuery = NonFinalCustomBuilder<NonFinalCustomBuilderModel> */

    $_scoped = $model->registerGlobalScopes($model->newModelQuery());
    /** @psalm-check-type-exact $_scoped = NonFinalCustomBuilder<NonFinalCustomBuilderModel> */

    accepts_invariant_builder_for_exact_model($model->newQuery());
}

/**
 * Covariant custom-builder templates can safely keep `$this`/`static`, which prevents
 * terminal model-returning builder calls from flattening `static` to the base model.
 */
function covariant_custom_builder_instance_query_methods_preserve_static(CovariantNonFinalCustomBuilderModel $model): void
{
    $_newQuery = $model->newQuery();
    /** @psalm-check-type-exact $_newQuery = CovariantNonFinalCustomBuilder<CovariantNonFinalCustomBuilderModel&static> */

    $_query = CovariantNonFinalCustomBuilderModel::query();
    /** @psalm-check-type-exact $_query = CovariantNonFinalCustomBuilder<CovariantNonFinalCustomBuilderModel&static> */

    $_scoped = $model->registerGlobalScopes($model->newModelQuery());
    /** @psalm-check-type-exact $_scoped = CovariantNonFinalCustomBuilder<CovariantNonFinalCustomBuilderModel&static> */

    CovariantNonFinalCustomBuilderModel::viaQuery();
    $model->viaNewQuery();
    $model->viaNewModelQuery();
    $model->viaNewQueryWithoutRelationships();
    $model->viaNewQueryWithoutScopes();
    $model->viaNewQueryWithoutScope();
    $model->viaNewQueryForRestoration();
}
?>
--EXPECTF--
