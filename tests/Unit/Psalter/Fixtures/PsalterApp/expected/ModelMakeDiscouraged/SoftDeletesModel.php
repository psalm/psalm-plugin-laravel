<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ModelMake;

use PsalterFixture\Models\ModelMakeSoftDeletesModel;

/**
 * `SoftDeletes` brings `bootSoftDeletes()`: reported, never rewritten.
 */
final class SoftDeletesModel
{
    public function build(): ModelMakeSoftDeletesModel
    {
        return ModelMakeSoftDeletesModel::make();
    }
}
