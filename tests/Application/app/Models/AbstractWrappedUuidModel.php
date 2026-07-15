<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractWrappedUuidModel extends Model
{
    use HasUuids;
}
