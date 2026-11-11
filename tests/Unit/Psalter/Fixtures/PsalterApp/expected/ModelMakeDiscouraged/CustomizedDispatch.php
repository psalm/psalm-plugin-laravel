<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ModelMake;

use PsalterFixture\Models\ImplicitQueryBuilderCallMagicCallModel;
use PsalterFixture\Models\ImplicitQueryBuilderCallNewQueryModel;
use PsalterFixture\Models\ImplicitQueryBuilderCallStaticMagicModel;
use PsalterFixture\Models\ModelMakeAttributeBuilderModel;
use PsalterFixture\Models\ModelMakeNewQueryModel;
use PsalterFixture\Models\ModelMakeUntypedBuilderModel;

/**
 * `make()` runs `__callStatic` -> `__call` -> `newQuery()` -> the Eloquent builder's `make()`, and `new` runs none
 * of that. A model that customizes any link of the chain (also through an ancestor or a trait, and also through
 * an untyped `newEloquentBuilder()` the plugin cannot resolve) is reported and never rewritten.
 */
final class CustomizedDispatch
{
    public function build(): void
    {
        ModelMakeUntypedBuilderModel::make(['title' => 'a']);
        ModelMakeAttributeBuilderModel::make(['title' => 'a']);
        ModelMakeNewQueryModel::make();
        ImplicitQueryBuilderCallMagicCallModel::make();
        ImplicitQueryBuilderCallStaticMagicModel::make();
        ImplicitQueryBuilderCallNewQueryModel::make();
    }
}
