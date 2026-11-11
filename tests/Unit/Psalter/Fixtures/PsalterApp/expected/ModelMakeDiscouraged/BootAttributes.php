<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ModelMake;

use PsalterFixture\Models\ModelMakeBootAttributeModel;
use PsalterFixture\Models\ModelMakeInitializeAttributeModel;
use PsalterFixture\Models\ModelMakeScopedBySubclassModel;
use PsalterFixture\Models\ModelMakeScopedByTraitModel;

/**
 * Laravel boots and initializes a model through `#[Boot]` / `#[Initialize]` methods of any name and collects
 * `#[ScopedBy]` (or a subclass of it) from the model, its ancestors and the traits they use: these calls are
 * reported and never rewritten.
 */
final class BootAttributes
{
    public function build(): void
    {
        ModelMakeScopedByTraitModel::make();
        ModelMakeScopedBySubclassModel::make();
        ModelMakeBootAttributeModel::make();
        ModelMakeInitializeAttributeModel::make();
    }
}
