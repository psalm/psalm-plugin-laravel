<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ModelMake;

use PsalterFixture\Models\ModelMakeNewInstanceModel;

final class NewInstanceOverride
{
    public function build(): ModelMakeNewInstanceModel
    {
        return ModelMakeNewInstanceModel::make();
    }
}
