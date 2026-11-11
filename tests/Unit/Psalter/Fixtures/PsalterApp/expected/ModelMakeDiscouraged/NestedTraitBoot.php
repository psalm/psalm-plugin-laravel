<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ModelMake;

use PsalterFixture\Models\ModelMakeNestedBootModel;

/**
 * A `#[Boot]` method of a trait used by a trait the model uses still runs when the model boots: reported,
 * never rewritten.
 */
final class NestedTraitBoot
{
    public function build(): ModelMakeNestedBootModel
    {
        return ModelMakeNestedBootModel::make();
    }
}
