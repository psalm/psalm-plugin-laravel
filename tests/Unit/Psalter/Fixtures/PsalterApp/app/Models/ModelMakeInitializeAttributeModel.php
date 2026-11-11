<?php

declare(strict_types=1);

namespace PsalterFixture\Models;

use Illuminate\Database\Eloquent\Attributes\Initialize;
use Illuminate\Database\Eloquent\Model;

/**
 * A method marked `#[Initialize]` runs for every new instance, whatever its name.
 */
class ModelMakeInitializeAttributeModel extends Model
{
    #[Initialize]
    protected function seedDefaults(): void
    {
        $this->setAttribute('seeded', true);
    }
}
