<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ModelMake;

use PsalterFixture\Models\ModelMakeCustomBuilderModel;

final class CustomBuilderMake
{
    public function build(): ModelMakeCustomBuilderModel
    {
        return ModelMakeCustomBuilderModel::make(['title' => 'a']);
    }
}
