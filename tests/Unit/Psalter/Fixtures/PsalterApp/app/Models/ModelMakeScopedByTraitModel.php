<?php

declare(strict_types=1);

namespace PsalterFixture\Models;

use Illuminate\Database\Eloquent\Model;

class ModelMakeScopedByTraitModel extends Model
{
    use ModelMakeScopedByTrait;
}
