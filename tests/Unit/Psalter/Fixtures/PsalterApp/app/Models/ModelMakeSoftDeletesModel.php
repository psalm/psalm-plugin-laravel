<?php

declare(strict_types=1);

namespace PsalterFixture\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * `SoftDeletes` boots a global scope through `bootSoftDeletes()`, so the creation path is not the stock one.
 */
class ModelMakeSoftDeletesModel extends Model
{
    use SoftDeletes;
}
